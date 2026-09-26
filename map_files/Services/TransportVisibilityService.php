<?php
class TransportVisibilityService
{
    private $matcher;

    public function __construct($matcher)
    {
        $this->matcher = $matcher;
    }

    public function filterTrackers(array $trackers, array $flights, array $drivers)
    {
        $driverById = [];
        foreach ($drivers as $driver) {
            if (!is_array($driver)) {
                continue;
            }
            $id = (int)($driver['id'] ?? 0);
            if ($id > 0) {
                $driverById[$id] = $driver;
            }
        }

        $flightByKey = [];
        foreach ($flights as $flight) {
            if (!is_array($flight)) {
                continue;
            }

            $driverId = (int)($flight['driver_id'] ?? 0);
            if ($driverId <= 0) {
                continue;
            }
            if (!isset($driverById[$driverId])) {
                continue;
            }

            $driver = $driverById[$driverId];
            $key = $this->matcher->buildDriverKey($driver);
            if (!$key) {
                continue;
            }

            if (!isset($flightByKey[$key])) {
                $flightByKey[$key] = [
                    'flight' => $flight,
                    'driver' => $driver,
                ];
            }
        }

        $result = [];
        $seenTrackers = [];
        foreach ($trackers as $tracker) {
            if (!is_array($tracker)) {
                continue;
            }

            $uniqueid = (string)($tracker['uniqueid'] ?? '');
            if ($uniqueid === '' || isset($seenTrackers[$uniqueid])) {
                continue;
            }

            $name = trim((string)($tracker['name'] ?? ''));
            if ($name === '' || preg_match('/^\d{6}$/', $name)) {
                continue;
            }

            if (!isset($tracker['lat'], $tracker['lon'])) {
                continue;
            }
            if (!is_numeric($tracker['lat']) || !is_numeric($tracker['lon'])) {
                continue;
            }

            $trackerKey = $this->matcher->buildTrackerKey($tracker);
            if (!$trackerKey || !isset($flightByKey[$trackerKey])) {
                continue;
            }

            $match = $flightByKey[$trackerKey];
            $flight = $match['flight'];
            $driver = $match['driver'];
            $status = (string)($flight['status'] ?? '');
            $reason = ($status === 'started') ? 'started' : 'planned_window';

            $tracker['matched_flight_id'] = (int)($flight['id'] ?? 0);
            $tracker['matched_flight_status'] = $status;
            $tracker['matched_driver_id'] = (int)($driver['id'] ?? 0);
            $tracker['matched_driver_name'] = (string)($driver['full_name'] ?? '');
            $tracker['matched_vehicle_plate'] = (string)($driver['vehicle_make_plate'] ?? '');
            $tracker['transport_visibility_reason'] = $reason;

            $result[] = $tracker;
            $seenTrackers[$uniqueid] = true;
        }

        return $result;
    }
}
