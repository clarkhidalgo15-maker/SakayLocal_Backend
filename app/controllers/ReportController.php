<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/ApiController.php';

class ReportController extends ApiController
{
    public function submit_report()
    {
        $api = $this->apiLibrary();
        $api->require_method('POST');
        $input = $api->body();
        $required = ['contact_name', 'report_type'];
        if (($input['report_type'] ?? '') !== 'feedback') {
            $required = array_merge($required, ['subject', 'description']);
        }
        foreach ($required as $field) {
            if (empty($input[$field])) {
                $this->respond(['error' => "{$field} is required."], 422);
            }
        }
        if (!in_array($input['report_type'], ['complaint', 'incident', 'assistance', 'feedback'], true)) {
            $this->respond(['error' => 'Invalid report type.'], 422);
        }
        $contactName = $this->text($input['contact_name'], 'Full name', 150);
        $this->database();
        $this->call->model('User_model');
        $user = $this->optionalAuthenticatedPassenger();
        $rating = null;
        $comments = null;
        if ($input['report_type'] === 'feedback') {
            $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
            if ($rating === false || $rating < 1 || $rating > 5) {
                $this->respond(['error' => 'Choose a service rating from 1 to 5 stars.'], 422);
            }
            $comments = $this->text($input['description'] ?? '', 'Comments', 2000, false);
            $subject = "Service rating: {$rating}/5";
            $description = $comments !== ''
                ? $comments
                : "Passenger rated this service {$rating} out of 5.";
        } else {
            $subject = $this->text($input['subject'], 'Subject', 200);
            $description = $this->text($input['description'], 'Details', 5000);
        }
        $reference = 'SL-' . date('Y') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $this->call->model('Passenger_report_model');
        $reportData = [
            'user_id' => $user['user_id'] ?? null,
            'contact_name' => $contactName,
            'contact_email' => $input['contact_email'] ?? null,
            'contact_phone' => $input['contact_phone'] ?? null,
            'notify_via' => $input['notify_via'] ?? 'email',
            'report_type' => $input['report_type'],
            'subject' => $subject,
            'description' => $description,
            'location_text' => $input['location_text'] ?? null,
            'reference_code' => $reference,
        ];
        if ($input['report_type'] === 'feedback') {
            $this->Passenger_report_model->createWithFeedback($reportData, $rating, $comments ?: null);
        } else {
            $this->Passenger_report_model->create($reportData);
        }
        $this->respond(['data' => ['reference_code' => $reference, 'status' => 'submitted']], 201);
    }

    public function report($reference = '')
    {
        $reference = $reference !== '' ? $reference : ($_GET['reference'] ?? '');
        if ($reference === '') {
            $this->respond(['error' => 'A reference code is required.'], 422);
        }
        $this->database();
        $this->call->model('Passenger_report_model');
        $report = $this->Passenger_report_model->findByReference($reference);
        if (!$report) {
            $this->respond(['error' => 'Report not found.'], 404);
        }
        $this->call->model('Report_status_history_model');
        $report['history'] = $this->Report_status_history_model->getForReport($report['report_id']);
        $this->respond(['data' => $report]);
    }
}
