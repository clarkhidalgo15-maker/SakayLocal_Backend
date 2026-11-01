<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Pickup_point_model extends Model
{
    protected $table = 'pickup_points';
    protected $primary_key = 'pickup_point_id';

    public function getAdminList($city = null)
    {
        $query = $this->db->table('pickup_points p')
            ->distinct()
            ->select('p.pickup_point_id AS id, p.route_id, r.route_name, r.origin_name, r.destination_name, r.is_active, p.point_name, p.latitude AS lat, p.longitude AS lng, p.sequence_no, p.workflow_status')
            ->join('routes r', 'r.route_id = p.route_id');
        if ($city !== null) {
            $query->join('route_terminals scope_rt', 'scope_rt.route_id = r.route_id')
                ->join('terminals scope_t', 'scope_t.terminal_id = scope_rt.terminal_id')
                ->join('areas scope_a', 'scope_a.area_id = scope_t.area_id')
                ->where('scope_a.city_municipality', $city);
        }

        return $query->order_by('r.is_active', 'DESC')
            ->order_by('p.route_id')
            ->order_by('p.sequence_no')
            ->get_all();
    }

    public function saveAdmin($id, array $data, $workflowStatus, $createdBy)
    {
        $fields = [
            'route_id' => (int) $data['route_id'],
            'point_name' => trim((string) $data['point_name']),
            'latitude' => (float) $data['lat'],
            'longitude' => (float) $data['lng'],
            'sequence_no' => (int) $data['sequence_no'],
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
        return $this->db->table('pickup_points x')
            ->select('r.route_id')
            ->join('routes r', 'r.route_id = x.route_id')
            ->join('route_terminals rt', 'rt.route_id = r.route_id')
            ->join('terminals t', 't.terminal_id = rt.terminal_id')
            ->join('areas a', 'a.area_id = t.area_id')
            ->where('x.pickup_point_id', (int) $id)
            ->where('a.city_municipality', $city)
            ->limit(1)
            ->get(PDO::FETCH_COLUMN) !== false;
    }
}
?>
