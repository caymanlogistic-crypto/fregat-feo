(function (root) {
    'use strict';

    function routeLength(order, distances, returnToOrigin = false) {
        let total = 0, previous = 0;
        for (const next of order) {
            total += distances[previous][next];
            previous = next;
        }
        return total + (returnToOrigin ? distances[previous][0] : 0);
    }

    // Fixed origin, optional return, directed road distances.
    // Held–Karp finds the minimum for up to 16 stops without enumerating permutations.
    function orderStops(distances, returnToOrigin = false) {
        const count = distances.length - 1;
        if (count < 1 || distances.some(row => row.length !== count + 1)) {
            throw new Error('Некорректная таблица дорожных расстояний');
        }
        for (let from = 0; from <= count; from++) {
            for (let to = returnToOrigin ? 0 : 1; to <= count; to++) {
                if (from !== to && (!Number.isFinite(distances[from][to]) || distances[from][to] < 0)) {
                    throw new Error('Яндекс не построил дорогу ко всем выбранным точкам');
                }
            }
        }
        if (count <= 16) {
            const states = 1 << count;
            const costs = new Float64Array(states * count).fill(Infinity);
            const parents = new Int8Array(states * count).fill(-1);
            for (let last = 0; last < count; last++) costs[(1 << last) * count + last] = distances[0][last + 1];
            for (let mask = 1; mask < states; mask++) {
                for (let last = 0; last < count; last++) {
                    const cost = costs[mask * count + last];
                    if (!Number.isFinite(cost)) continue;
                    for (let next = 0; next < count; next++) {
                        if (mask & (1 << next)) continue;
                        const index = (mask | (1 << next)) * count + next;
                        const candidate = cost + distances[last + 1][next + 1];
                        if (candidate < costs[index]) {
                            costs[index] = candidate;
                            parents[index] = last;
                        }
                    }
                }
            }
            let mask = states - 1, last = 0;
            const endCost = index => costs[mask * count + index] + (returnToOrigin ? distances[index + 1][0] : 0);
            for (let i = 1; i < count; i++) {
                if (endCost(i) < endCost(last)) last = i;
            }
            const order = [];
            while (last !== -1) {
                order.push(last + 1);
                const previous = parents[mask * count + last];
                mask ^= 1 << last;
                last = previous;
            }
            return {order: order.reverse(), exact: true};
        }

        // Larger selections use directed road costs too, never straight-line distances.
        // Keep the distinction from a proven minimum visible to the dispatcher.
        let best = null, bestLength = Infinity;
        for (let first = 1; first <= count; first++) {
            const order = [first], remaining = new Set(Array.from({length: count}, (_, i) => i + 1));
            remaining.delete(first);
            while (remaining.size) {
                const from = order[order.length - 1];
                let next = remaining.values().next().value;
                for (const candidate of remaining) {
                    if (distances[from][candidate] < distances[from][next]) next = candidate;
                }
                order.push(next);
                remaining.delete(next);
            }
            const length = routeLength(order, distances, returnToOrigin);
            if (length < bestLength) { best = order; bestLength = length; }
        }
        for (let pass = 0; pass < 4; pass++) {
            let improved = false;
            for (let from = 0; from < count; from++) {
                for (let to = from + 1; to < count; to++) {
                    const candidate = best.slice(0, from).concat(best.slice(from, to + 1).reverse(), best.slice(to + 1));
                    const length = routeLength(candidate, distances, returnToOrigin);
                    if (length < bestLength) { best = candidate; bestLength = length; improved = true; }
                }
            }
            if (!improved) break;
        }
        return {order: best, exact: false};
    }

    // Pack required directed pairs into routes of at most 9 points to reduce API calls.
    // Incoming edges to the origin are included only for a closed route.
    function roadBatches(count, returnToOrigin = false) {
        const pending = Array.from({length: count + 1}, (_, from) =>
            new Set(Array.from({length: count + (returnToOrigin ? 1 : 0)}, (_, i) => i + (returnToOrigin ? 0 : 1)).filter(to => to !== from)));
        const batches = [];
        for (;;) {
            let from = pending.findIndex(targets => targets.size > 0);
            if (from === -1) return batches;
            const batch = [from];
            while (batch.length < 9 && pending[from].size) {
                const to = pending[from].values().next().value;
                pending[from].delete(to);
                batch.push(to);
                from = to;
            }
            batches.push(batch);
        }
    }

    async function roadDistances(points, loadLegs, onProgress, cancelled, returnToOrigin = false) {
        const distances = points.map((_, from) => points.map((__, to) => from === to ? 0 : Infinity));
        const batches = roadBatches(points.length - 1, returnToOrigin);
        for (let i = 0; i < batches.length; i++) {
            if (cancelled()) throw new Error('Расчёт отменён');
            const batch = batches[i];
            const alternatives = await loadLegs(batch.map(index => points[index]));
            if (cancelled()) throw new Error('Расчёт отменён');
            for (const legs of alternatives) {
                if (legs.length !== batch.length - 1) continue;
                legs.forEach((distance, leg) => {
                    if (Number.isFinite(distance) && distance >= 0) {
                        const from = batch[leg], to = batch[leg + 1];
                        distances[from][to] = Math.min(distances[from][to], distance);
                    }
                });
            }
            onProgress(i + 1, batches.length);
        }
        return distances;
    }

    function candidateOrders(points, limit, returnToOrigin = false) {
        // Coordinates only propose a few orders. They never supply the displayed km.
        // Every candidate is measured on Yandex roads, under the caller's fixed budget.
        const radians = value => value * Math.PI / 180;
        const distances = points.map(from => points.map(to => {
            const lat = radians(to[0] - from[0]), lon = radians(to[1] - from[1]);
            const h = Math.sin(lat / 2) ** 2 + Math.cos(radians(from[0])) * Math.cos(radians(to[0])) * Math.sin(lon / 2) ** 2;
            return 2 * Math.atan2(Math.sqrt(h), Math.sqrt(Math.max(0, 1 - h)));
        }));
        const proposed = orderStops(distances, returnToOrigin).order;
        const original = points.slice(1).map((_, i) => i + 1);
        const candidates = [], seen = new Set();
        const add = order => {
            const key = order.join(',');
            if (candidates.length < limit && !seen.has(key)) { seen.add(key); candidates.push(order); }
        };
        add(proposed);
        add(proposed.slice().reverse());
        add(original);
        add(original.slice().reverse());
        for (let i = 0; i < proposed.length - 1 && candidates.length < limit; i++) {
            const order = proposed.slice();
            [order[i], order[i + 1]] = [order[i + 1], order[i]];
            add(order);
        }
        return candidates;
    }

    const api = {orderStops, roadBatches, roadDistances, routeLength, candidateOrders};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.FregatRouteOptimization = api;
})(typeof window !== 'undefined' ? window : this);
