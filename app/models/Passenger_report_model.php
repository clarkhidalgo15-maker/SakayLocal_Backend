<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Passenger_report_model extends Model
{
    protected $table = 'passenger_reports';
    protected $primary_key = 'report_id';

    public function create(array $data)
    {
        return $this->db->table($this->table)->insert([
            'user_id' => $data['user_id'] ?? null,
            'contact_name' => trim((string) $data['contact_name']),
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'notify_via' => $data['notify_via'] ?? 'email',
            'report_type' => $data['report_type'],
            'subject' => trim((string) $data['subject']),
            'description' => trim((string) $data['description']),
            'location_text' => $data['location_text'] ?? null,
            'reference_code' => $data['reference_code'],
        ]);
    }

    public function createWithFeedback(array $reportData, $rating, $comments = null)
    {
        $this->db->transaction();
        try {
            $reportId = $this->create($reportData);
            $this->db->table('feedback')->insert([
                'report_id' => (int) $reportId,
                'rating' => (int) $rating,
                'comments' => $comments,
            ]);
            $this->db->commit();
            return $reportId;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function findByReference($reference)
    {
        return $this->db->table($this->table)
            ->select('report_id, reference_code, report_type, subject, description, status, admin_remarks, created_at, updated_at')
            ->where('reference_code', strtoupper(trim((string) $reference)))
            ->limit(1)
            ->get();
    }

    public function getForUser($userId)
    {
        return $this->db->table($this->table)
            ->select('report_id AS id, reference_code, report_type, subject, status, admin_remarks, created_at, updated_at')
            ->where('user_id', (int) $userId)
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all();
    }

    public function getAdminList($city = null)
    {
        $query = $this->db->table('passenger_reports pr')
            ->distinct()
            ->select('pr.report_id AS id, pr.reference_code, pr.report_type, pr.subject, pr.description, pr.status, pr.admin_remarks, pr.contact_name, pr.created_at, pr.updated_at')
            ->left_join('terminals t', 't.terminal_id = pr.terminal_id')
            ->left_join('areas a', 'a.area_id = t.area_id')
            ->left_join('route_terminals rt', 'rt.route_id = pr.route_id')
            ->left_join('terminals route_t', 'route_t.terminal_id = rt.terminal_id')
            ->left_join('areas route_a', 'route_a.area_id = route_t.area_id');
        if ($city !== null) {
            $query->grouped(function ($query) use ($city) {
                $query->where('a.city_municipality', $city)
                    ->or_where('route_a.city_municipality', $city);
            });
        }

        return $query->order_by('pr.created_at', 'DESC')->get_all();
    }

    public function belongsToCity($id, $city)
    {
        $result = $this->db->table('passenger_reports pr')
            ->select_count('*', 'total')
            ->left_join('terminals t', 't.terminal_id = pr.terminal_id')
            ->left_join('areas a', 'a.area_id = t.area_id')
            ->left_join('route_terminals rt', 'rt.route_id = pr.route_id')
            ->left_join('terminals route_t', 'route_t.terminal_id = rt.terminal_id')
            ->left_join('areas route_a', 'route_a.area_id = route_t.area_id')
            ->where('pr.report_id', (int) $id)
            ->grouped(function ($query) use ($city) {
                $query->where('a.city_municipality', $city)
                    ->or_where('route_a.city_municipality', $city);
            })
            ->get();

        return (int) $result['total'] > 0;
    }

    public function updateStatusWithHistory($reportId, $status, $remarks = null)
    {
        $this->db->transaction();
        try {
            $current = $this->db->table($this->table)
                ->select('status')
                ->where($this->primary_key, (int) $reportId)
                ->get(PDO::FETCH_COLUMN);
            if ($current === false) {
                $this->db->roll_back();
                return false;
            }

            $this->db->table($this->table)
                ->where($this->primary_key, (int) $reportId)
                ->update(['status' => $status, 'admin_remarks' => $remarks]);

            if ($current !== $status) {
                $this->db->table('report_status_history')->insert([
                    'report_id' => (int) $reportId,
                    'new_status' => $status,
                    'old_status' => $current,
                    'remarks' => $remarks,
                ]);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }
}
?>
