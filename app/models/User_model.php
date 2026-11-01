<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class User_model extends Model
{
    protected $table = 'users';
    protected $primary_key = 'user_id';

    public function findByIdentifier($identifier)
    {
        return $this->db->table($this->table)
            ->select('user_id, full_name, email, role, assigned_city, password_hash, phone, profile_photo, notify_advisories, notify_weather')
            ->grouped(function ($query) use ($identifier) {
                $query->where('email', $identifier)
                    ->or_where('full_name', $identifier);
            })
            ->where('is_active', 1)
            ->limit(1)
            ->get();
    }

    public function findActiveAdministratorById($id)
    {
        return $this->db->table($this->table)
            ->select('user_id, role, assigned_city, password_hash')
            ->where($this->primary_key, (int) $id)
            ->in('role', ['admin', 'staff'])
            ->where('is_active', 1)
            ->limit(1)
            ->get();
    }

    public function create(array $data)
    {
        return $this->db->table($this->table)->insert([
            'full_name' => trim((string) $data['full_name']),
            'email' => strtolower(trim((string) $data['email'])),
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'role' => 'passenger',
            'phone' => $data['phone'] ?? null,
        ]);
    }

    public function findActiveById($id)
    {
        return $this->db->table($this->table)
            ->select('user_id, full_name, email, role, assigned_city, password_hash, phone, profile_photo, notify_advisories, notify_weather')
            ->where($this->primary_key, (int) $id)
            ->where('is_active', 1)
            ->limit(1)
            ->get();
    }

    public function updateProfile($id, array $data)
    {
        $fields = [
            'full_name' => trim((string) $data['full_name']),
            'email' => strtolower(trim((string) $data['email'])),
            'phone' => $data['phone'] ?: null,
            'notify_advisories' => (int) (bool) $data['notify_advisories'],
            'notify_weather' => (int) (bool) $data['notify_weather'],
        ];
        if (array_key_exists('profile_photo', $data)) {
            $fields['profile_photo'] = $data['profile_photo'];
        }

        return $this->db->table($this->table)
            ->where($this->primary_key, (int) $id)
            ->where('is_active', 1)
            ->update($fields);
    }

    public function getAdminList()
    {
        return $this->db->table($this->table)
            ->select('user_id AS id, full_name, email, role, assigned_city, phone, is_active, created_at')
            ->where('is_active', 1)
            ->order_by('created_at', 'DESC')
            ->get_all();
    }

    public function cityExists($city)
    {
        $result = $this->db->table('areas')
            ->select_count('*', 'total')
            ->where('city_municipality', $city)
            ->get();

        return (int) $result['total'] > 0;
    }

    public function saveAdmin($id, array $data)
    {
        $fields = [
            'full_name' => trim((string) $data['full_name']),
            'email' => trim((string) $data['email']),
            'role' => $data['role'],
            'assigned_city' => $data['assigned_city'] ?? null,
            'phone' => $data['phone'] ?? null,
        ];
        if ($id > 0) {
            $this->db->table($this->table)
                ->where($this->primary_key, (int) $id)
                ->where('role', '<>', 'admin')
                ->update($fields);
            return (int) $id;
        }

        $fields['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        return $this->db->table($this->table)->insert($fields);
    }

    public function deactivate($id, $protectAdmin = true)
    {
        $query = $this->db->table($this->table)
            ->where($this->primary_key, (int) $id);
        if ($protectAdmin) {
            $query->where('role', '<>', 'admin');
        }

        return $query->update(['is_active' => 0]) > 0;
    }
}
?>
