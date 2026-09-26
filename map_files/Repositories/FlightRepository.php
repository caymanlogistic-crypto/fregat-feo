<?php
class FlightRepository
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchStatusesForActual()
    {
        try {
            $stmt = $this->pdo->query("SELECT id, zayavki_ids, status FROM flights ORDER BY block_date DESC");
            if (!$stmt) {
                mapError('FlightRepository query returned false');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            mapError('FlightRepository query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getTransportRelevantFlights()
    {
        $sql = "
            SELECT
                id,
                status,
                driver_id,
                planned_start_date_from,
                planned_start_date_to,
                actual_start_date,
                actual_end_date
            FROM flights
            WHERE
                status = 'started'
                OR (
                    status IN ('search', 'found', 'attention', 'planned_route')
                    AND (
                        planned_start_date_from IS NOT NULL
                        OR planned_start_date_to IS NOT NULL
                    )
                )
            ORDER BY
                CASE WHEN status = 'started' THEN 0 ELSE 1 END,
                id DESC
        ";

        try {
            $stmt = $this->pdo->query($sql);
            if (!$stmt) {
                mapError('FlightRepository transport query returned false');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            mapError('FlightRepository transport query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getFlightsForRouteBlocks()
    {
        // IMPORTANT: the production flights table does NOT have name/route_name/direction columns.
        // The route title is stored in flights.comment. Expose compatible aliases for the UI layer.
        $sql = "
            SELECT
                id,
                status,
                driver_id,
                zayavki_ids,
                comment AS name,
                comment,
                comment AS route_name,
                comment AS direction,
                cost,
                planned_start_date_from,
                planned_start_date_to
            FROM flights
            WHERE status IN ('planned_route', 'found')
            ORDER BY id DESC
        ";

        try {
            $stmt = $this->pdo->query($sql);
            if (!$stmt) {
                mapError('FlightRepository route blocks query returned false');
                return [];
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            mapError('FlightRepository route blocks query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getFlightById($flightId)
    {
        $flightId = (int)$flightId;
        if ($flightId <= 0) {
            return null;
        }

        $sql = "SELECT id, status, driver_id, planned_start_date_from, planned_start_date_to, actual_start_date, actual_end_date, cost, zayavki_ids, route_type, source_warehouse_id, destination_warehouse_id FROM flights WHERE id = ? LIMIT 1";
        try {
            $stmt = $this->pdo->prepare($sql);
            if (!$stmt) {
                mapError('FlightRepository getFlightById prepare failed');
                return null;
            }
            if (!$stmt->execute([$flightId])) {
                mapError('FlightRepository getFlightById execute failed');
                return null;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            mapError('FlightRepository getFlightById failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function updateFlightPlanningFields($flightId, $plannedFrom, $plannedTo, $driverId, $cost)
    {
        $flightId = (int)$flightId;
        if ($flightId <= 0) {
            return false;
        }

        $sql = "
            UPDATE flights
            SET planned_start_date_from = :planned_from,
                planned_start_date_to = :planned_to,
                driver_id = :driver_id,
                cost = :cost
            WHERE id = :id
            LIMIT 1
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            if (!$stmt) {
                mapError('FlightRepository updateFlightPlanningFields prepare failed');
                return false;
            }
            return (bool)$stmt->execute([
                ':planned_from' => $plannedFrom,
                ':planned_to' => $plannedTo,
                ':driver_id' => $driverId,
                ':cost' => $cost,
                ':id' => $flightId,
            ]);
        } catch (Throwable $e) {
            mapError('FlightRepository updateFlightPlanningFields failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function updateFlightStatus($flightId, $status, $actualStartDate = null)
    {
        $flightId = (int)$flightId;
        $status = trim((string)$status);
        if ($flightId <= 0 || $status === '') {
            return false;
        }

        if ($status === 'started') {
            $sql = "
                UPDATE flights
                SET status = :status,
                    actual_start_date = :actual_start_date
                WHERE id = :id
                LIMIT 1
            ";
            $params = [
                ':status' => $status,
                ':actual_start_date' => $actualStartDate,
                ':id' => $flightId,
            ];
        } else {
            $sql = "
                UPDATE flights
                SET status = :status
                WHERE id = :id
                LIMIT 1
            ";
            $params = [
                ':status' => $status,
                ':id' => $flightId,
            ];
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            if (!$stmt) {
                mapError('FlightRepository updateFlightStatus prepare failed');
                return false;
            }
            return (bool)$stmt->execute($params);
        } catch (Throwable $e) {
            mapError('FlightRepository updateFlightStatus failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
