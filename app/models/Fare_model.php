<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Fare_model extends Model
{
    protected $table = 'fares';
    protected $primary_key = 'fare_id';

    public function getAdminList($city = null)
    {
        $query = $this->db->table('fares f')
            ->distinct()
            ->select('f.fare_id AS id, f.route_id, r.route_name, r.origin_name, r.destination_name, r.is_active, f.origin_point_id, f.destination_point_id, f.fare_amount, f.notes, f.workflow_status')
            ->join('routes r', 'r.route_id = f.route_id');
        if ($city !== null) {
            $query->join('route_terminals scope_rt', 'scope_rt.route_id = r.route_id')
                ->join('terminals scope_t', 'scope_t.terminal_id = scope_rt.terminal_id')
                ->join('areas scope_a', 'scope_a.area_id = scope_t.area_id')
                ->where('scope_a.city_municipality', $city);
        }

        return $query->order_by('r.is_active', 'DESC')
            ->order_by('f.fare_id', 'DESC')
            ->get_all();
    }

    public function saveAdmin($id, array $data, $workflowStatus, $createdBy)
    {
        $fields = [
            'route_id' => (int) $data['route_id'],
            'origin_point_id' => $data['origin_point_id'] ?? null,
            'destination_point_id' => $data['destination_point_id'] ?? null,
            'fare_amount' => (float) $data['fare_amount'],
            'notes' => $data['notes'] ?? null,
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

    public function deleteById($id)
    {
        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->delete() > 0;
    }

    public function hasActiveRoute($routeId)
    {
        $result = $this->db->table('routes')
            ->select_count('*', 'total')
            ->where('route_id', (int) $routeId)
            ->where('is_active', 1)
            ->get();

        return (int) $result['total'] > 0;
    }

    public function setWorkflow($id, $status, $reviewedBy, $remarks)
    {
        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->update([
                'workflow_status' => $status,
                'reviewed_by' => (int) $reviewedBy,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_remarks' => $remarks,
            ]);
    }

    public function belongsToCity($id, $city)
    {
        return $this->db->table('fares x')
            ->select('r.route_id')
            ->join('routes r', 'r.route_id = x.route_id')
            ->join('route_terminals rt', 'rt.route_id = r.route_id')
            ->join('terminals t', 't.terminal_id = rt.terminal_id')
            ->join('areas a', 'a.area_id = t.area_id')
            ->where('x.fare_id', (int) $id)
            ->where('a.city_municipality', $city)
            ->limit(1)
            ->get(PDO::FETCH_COLUMN) !== false;
    }
}
?>
