<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Route_terminal_model extends Model
{
    protected $table = 'route_terminals';
    protected $primary_key = 'route_terminal_id';

    public function replaceForRoute($routeId, $originTerminalId, $destinationTerminalId)
    {
        $this->db->transaction();
        try {
            $this->db->table($this->table)
                ->where('route_id', (int) $routeId)
                ->delete();
            $this->db->table($this->table)->insert([
                'route_id' => (int) $routeId,
                'terminal_id' => (int) $originTerminalId,
                'stop_order' => 1,
            ]);
            if ((int) $destinationTerminalId !== (int) $originTerminalId) {
                $this->db->table($this->table)->insert([
                    'route_id' => (int) $routeId,
                    'terminal_id' => (int) $destinationTerminalId,
                    'stop_order' => 2,
                ]);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }
}
?>
