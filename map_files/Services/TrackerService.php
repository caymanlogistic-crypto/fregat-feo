<?php
class TrackerService
{
    private $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    public function getTrackers()
    {
        $trackers = [];
        $devices = $this->client->fetchDevices();
        $adminFields = $this->client->fetchAdminFields();
        if (!is_array($devices)) {
            $devices = [];
        }
        if (!is_array($adminFields)) {
            $adminFields = [];
        }

        $merged = [];
        foreach ($devices as $d) {
            if (!is_array($d) || empty($d['uniqueid'])) {
                continue;
            }
            $merged[$d['uniqueid']] = $d;
        }

        foreach ($adminFields as $f) {
            if (!is_array($f) || empty($f['uniqueid'])) {
                continue;
            }
            $uid = $f['uniqueid'];
            if (isset($merged[$uid])) {
                $merged[$uid]['admin_fields'] = $f['fields'] ?? [];
            } else {
                $merged[$uid] = [
                    'uniqueid' => $uid,
                    'name' => $f['name'] ?? null,
                    'admin_fields' => $f['fields'] ?? [],
                ];
            }
        }

        foreach ($merged as $t) {
            if (!is_array($t) || empty($t['uniqueid'])) {
                continue;
            }
            $name = $t['name'] ?? '';
            if (preg_match('/^\d{6}$/', $name)) {
                continue;
            }
            if (empty($t['lat']) || empty($t['lon'])) {
                continue;
            }

            $lastupdateMSK = utcToMoscow($t['lastupdate'] ?? '');
            $diff = getTimeDiff($lastupdateMSK);

            $trackers[] = [
                'uniqueid' => $t['uniqueid'],
                'name' => $name,
                'short_name' => getShortName($name),
                'lat' => (float)$t['lat'],
                'lon' => (float)$t['lon'],
                'lastupdate_msk' => $lastupdateMSK,
                'time_diff_text' => $diff['text'],
                'time_diff_minutes' => $diff['minutes'],
                'status' => $t['status'] ?? 'unknown',
            ];
        }

        return $trackers;
    }
}
