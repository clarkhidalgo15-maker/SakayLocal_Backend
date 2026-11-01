<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Area_model extends Model
{
    protected $table = 'areas';
    protected $primary_key = 'area_id';

    public function getPublicList()
    {
        return $this->db->table($this->table)
            ->select('area_id AS id, area_name AS name, city_municipality AS city, province')
            ->order_by('city_municipality')
            ->order_by('area_name')
            ->get_all();
    }

    public function getAdminList($city = null)
    {
        $query = $this->db->table($this->table)
            ->select('area_id AS id, area_name AS name, city_municipality AS city, province');
        if ($city !== null) {
            $query->where('city_municipality', $city);
        }

        return $query->order_by('city_municipality')
            ->order_by('area_name')
            ->get_all();
    }

    public function hasActiveTerminals($id)
    {
        $result = $this->db->table('terminals')
            ->select_count('*', 'total')
            ->where('area_id', (int) $id)
            ->where('is_active', 1)
            ->get();

        return (int) $result['total'] > 0;
    }

    public function findCityById($id)
    {
        return $this->db->table($this->table)
            ->select('city_municipality')
            ->where($this->primary_key, (int) $id)
            ->get(PDO::FETCH_COLUMN);
    }

    public function existsById($id)
    {
        return $this->findCityById($id) !== false;
    }

    public function saveAdmin($id, array $data)
    {
        $fields = [
            'area_name' => trim((string) $data['name']),
            'city_municipality' => trim((string) $data['city']),
            'province' => trim((string) $data['province']),
        ];
        if ($id > 0) {
            $this->db->table($this->table)
                ->where($this->primary_key, (int) $id)
                ->update($fields);
            return (int) $id;
        }

        return $this->db->table($this->table)->insert($fields);
    }

    public function deleteById($id)
    {
        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->delete() > 0;
    }
}
?>
