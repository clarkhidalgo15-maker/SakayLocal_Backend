<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Route_model extends Model
{
    protected $table = 'routes';
    protected $primary_key = 'route_id';

    public function getPublicList()
    {
        return $this->db->table('routes r')
            ->select('r.route_id AS id, r.route_name AS name, r.vehicle_type AS vehicle, r.origin_name AS origin, r.destination_name AS destination, s.schedule_label AS schedule')
            ->select_min('f.fare_amount', 'fare')
            ->select_raw(
                "COALESCE(
                    (SELECT GROUP_CONCAT(CONCAT(pp.latitude, ',', pp.longitude) ORDER BY pp.sequence_no SEPARATOR '|')
                     FROM pickup_points pp WHERE pp.route_id = r.route_id AND pp.workflow_status IN ('approved','published')),
                    (SELECT GROUP_CONCAT(CONCAT(t.latitude, ',', t.longitude) ORDER BY rt.stop_order SEPARATOR '|')
                     FROM route_terminals rt JOIN terminals t ON t.terminal_id = rt.terminal_id
                     WHERE rt.route_id = r.route_id AND t.is_active = 1 AND t.workflow_status IN ('approved','published'))
                ) AS map_points"
            )
            ->left_join('fares f', "f.route_id = r.route_id AND f.workflow_status IN ('approved','published')")
            ->left_join('schedules s', "s.route_id = r.route_id AND s.workflow_status IN ('approved','published')")
            ->where('r.is_active', 1)
            ->in('r.workflow_status', ['approved', 'published'])
            ->group_by(['r.route_id', 's.schedule_id'])
            ->order_by('r.route_name')
            ->get_all();
    }

    public function getAdminList($city = null)
    {
        $query = $this->db->table('routes r')
            ->select('r.route_id AS id, r.route_name AS name, r.vehicle_type AS vehicle, r.origin_name AS origin, r.destination_name AS destination, r.description, r.is_active, r.workflow_status, r.review_remarks')
            ->select_raw('MAX(CASE WHEN rt.stop_order = 1 THEN rt.terminal_id END) AS origin_terminal_id')
            ->select_raw('MAX(CASE WHEN rt.stop_order = 2 THEN rt.terminal_id END) AS destination_terminal_id');
        if ($city !== null) {
            $query->join('route_terminals scope_rt', 'scope_rt.route_id = r.route_id')
                ->join('terminals scope_t', 'scope_t.terminal_id = scope_rt.terminal_id')
                ->join('areas scope_a', 'scope_a.area_id = scope_t.area_id')
                ->where('scope_a.city_municipality', $city);
        }

        return $query->left_join('route_terminals rt', 'rt.route_id = r.route_id')
            ->group_by('r.route_id')
            ->order_by('r.is_active', 'DESC')
            ->order_by('r.route_name')
            ->get_all();
    }

    public function saveAdmin($id, array $data, $workflowStatus = null, $createdBy = null)
    {
        $fields = [
            'route_name' => trim((string) $data['name']),
            'vehicle_type' => $data['vehicle'],
            'origin_name' => trim((string) $data['origin']),
            'destination_name' => trim((string) $data['destination']),
            'description' => $data['description'] ?? null,
        ];
        if ($id > 0) {
            $this->db->table($this->table)
                ->where($this->primary_key, (int) $id)
                ->update($fields);
            return (int) $id;
        }

        $fields['workflow_status'] = $workflowStatus;
        $fields['created_by'] = (int) $createdBy;
        return $this->db->table($this->table)->insert($fields);
    }

    public function setActive($id, $active)
    {
        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->update(['is_active' => (int) (bool) $active]);
    }

    public function permanentlyDelete($id)
    {
        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->delete() > 0;
    }

    public function setWorkflow($id, $status, $active, $reviewedBy, $remarks)
    {
        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->update([
                'workflow_status' => $status,
                'is_active' => (int) (bool) $active,
                'reviewed_by' => (int) $reviewedBy,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_remarks' => $remarks,
            ]);
    }

    public function belongsToCity($id, $city)
    {
        $result = $this->db->table('route_terminals rt')
            ->select_count('*', 'total')
            ->join('terminals t', 't.terminal_id = rt.terminal_id')
            ->join('areas a', 'a.area_id = t.area_id')
            ->where('rt.route_id', (int) $id)
            ->where('a.city_municipality', $city)
            ->get();

        return (int) $result['total'] > 0;
    }
}
?>
