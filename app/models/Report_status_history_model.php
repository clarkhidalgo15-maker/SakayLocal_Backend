<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Report_status_history_model extends Model
{
    protected $table = 'report_status_history';
    protected $primary_key = 'history_id';

    public function getForReport($reportId)
    {
        return $this->db->table($this->table)
            ->select('old_status, new_status, remarks, changed_at')
            ->where('report_id', (int) $reportId)
            ->order_by('changed_at')
            ->get_all();
    }
}
?>
