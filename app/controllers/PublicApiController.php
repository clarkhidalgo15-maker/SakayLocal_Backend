<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/ApiController.php';

class PublicApiController extends ApiController
{
    public function health()
        {
            $this->respond(['ok' => true, 'service' => 'SakayLokal API']);
        }

    public function terminals()
        {
            $this->database();
            $this->call->model('Terminal_model');
            $this->respond(['data' => $this->Terminal_model->getPublicList()]);
        }

    public function advisories()
        {
            $this->database();
            $this->call->model('Advisory_model');
            $this->respond(['data' => $this->Advisory_model->getPublicList()]);
        }

    public function routes()
        {
            $this->database();
            $this->call->model('Route_model');
            $this->respond(['data' => $this->Route_model->getPublicList()]);
        }

    public function areas()
        {
            $this->database();
            $this->call->model('Area_model');
            $this->respond(['data' => $this->Area_model->getPublicList()]);
        }

}
