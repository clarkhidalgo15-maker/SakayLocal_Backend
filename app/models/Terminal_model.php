<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Terminal_model extends Model
{
    protected $table = 'terminals';
    protected $primary_key = 'terminal_id';

    public function getPublicList()
    {
        return $this->db->table('terminals t')
            ->select('t.terminal_id AS id, t.terminal_name AS name, t.address, t.landmark, t.contact_no, t.operating_hours AS hours, a.area_name AS area, a.city_municipality AS city, t.latitude AS lat, t.longitude AS lng')
            ->select_raw('GROUP_CONCAT(DISTINCT r.vehicle_type ORDER BY r.vehicle_type) AS vehicle_types')
            ->select_raw('GROUP_CONCAT(DISTINCT r.route_name ORDER BY r.route_name) AS routes')
            ->select_raw("GROUP_CONCAT(DISTINCT CONCAT(r.route_id, ':', r.route_name, ':', r.vehicle_type) SEPARATOR '|') AS route_data")
            ->join('areas a', 'a.area_id = t.area_id')
            ->left_join('route_terminals rt', 'rt.terminal_id = t.terminal_id')
            ->left_join('routes r', "r.route_id = rt.route_id AND r.is_active = 1 AND r.workflow_status IN ('approved','published')")
            ->where('t.is_active', 1)
            ->in('t.workflow_status', ['approved', 'published'])
            ->group_by('t.terminal_id')
            ->order_by('t.terminal_name')
            ->get_all();
    }

    public function getAdminList($city = null)
    {
        $query = $this->db->table('terminals t')
            ->select('t.terminal_id AS id, t.area_id, a.area_name AS area, a.city_municipality AS city, a.province, t.terminal_name AS name, t.address, t.landmark, t.latitude AS lat, t.longitude AS lng, t.operating_hours AS hours, t.contact_no, t.is_active, t.workflow_status, t.review_remarks')
            ->join('areas a', 'a.area_id = t.area_id');
        if ($city !== null) {
            $query->where('a.city_municipality', $city);
        }

        return $query->order_by('t.is_active', 'DESC')
            ->order_by('t.terminal_name')
            ->get_all();
    }

    public function saveAdmin($id, array $data, $workflowStatus = null, $createdBy = null)
    {
        $fields = [
            'area_id' => (int) $data['area_id'],
            'terminal_name' => trim((string) $data['name']),
            'address' => trim((string) $data['address']),
            'landmark' => $data['landmark'] ?? null,
            'latitude' => (float) $data['lat'],
            'longitude' => (float) $data['lng'],
            'operating_hours' => trim((string) $data['hours']),
            'contact_no' => $data['contact_no'] ?? null,
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
        $result = $this->db->table('terminals t')
            ->select_count('*', 'total')
            ->join('areas a', 'a.area_id = t.area_id')
            ->where('t.terminal_id', (int) $id)
            ->where('a.city_municipality', $city)
            ->get();

        return (int) $result['total'] > 0;
    }

    public function countActiveByIds($firstId, $secondId)
    {
        $result = $this->db->table($this->table)
            ->select_count('*', 'total')
            ->in('terminal_id', [(int) $firstId, (int) $secondId])
            ->where('is_active', 1)
            ->get();

        return (int) $result['total'];
    }
}
?>
