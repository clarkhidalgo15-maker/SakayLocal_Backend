<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Advisory_model extends Model
{
    protected $table = 'advisories';
    protected $primary_key = 'advisory_id';

    public function getPublicList()
    {
        return $this->db->table($this->table)
            ->select('advisory_id AS id, title, body, advisory_type, created_at')
            ->where('is_active', 1)
            ->in('workflow_status', ['approved', 'published'])
            ->order_by('advisory_id', 'DESC')
            ->get_all();
    }

    public function getAdminList()
    {
        return $this->db->table($this->table)
            ->select('advisory_id AS id, title, body, advisory_type, recipient_audience, is_active, workflow_status, created_at')
            ->order_by('is_active', 'DESC')
            ->order_by('advisory_id', 'DESC')
            ->get_all();
    }

    public function saveAdmin($id, array $data, $workflowStatus = null, $createdBy = null)
    {
        $fields = [
            'title' => $data['title'],
            'body' => $data['body'],
            'advisory_type' => $data['advisory_type'],
            'recipient_audience' => $data['recipient_audience'],
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

    public function deleteById($id)
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

}
?>
