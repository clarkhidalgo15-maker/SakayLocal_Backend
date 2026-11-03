<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/ApiController.php';

class AccountController extends ApiController
{
    public function admin_account_password()
    {
        $user = $this->requireAdmin();
        $this->changeOwnPassword($user, true);
    }

    public function account_password()
    {
        $user = $this->requireAuthenticatedUser();
        $this->changeOwnPassword($user, false);
    }

    private function changeOwnPassword(array $user, $administrator)
    {
        $this->apiLibrary()->require_method('POST');
        $input = $this->input(true);
        $currentPassword = is_string($input['current_password'] ?? null) ? $input['current_password'] : '';
        $newPassword = is_string($input['new_password'] ?? null) ? $input['new_password'] : '';
        if ($currentPassword === '' || $newPassword === '') {
            $this->respond(['error' => 'Current password and new password are required.'], 422);
        }
        if (!password_verify($currentPassword, $user['password_hash'])) {
            $this->respond(['error' => 'Current password is incorrect.'], 422);
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 255) {
            $this->respond(['error' => 'New password must be between 6 and 255 characters.'], 422);
        }
        if (!$this->User_model->updatePassword($user['user_id'], $newPassword, $administrator)) {
            $this->respond(['error' => 'Could not change your password. Please try again.'], 500);
        }
        $updated = $this->User_model->findActiveById($user['user_id']);
        $this->respond(['data' => [
            'access_token' => $this->createAdminToken($updated),
            'message' => 'Password changed successfully.',
        ]]);
    }

    public function admin_account()
    {
        $user = $this->requireAdmin();
        $profile = $this->User_model->findActiveById($user['user_id']);
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            unset($profile['password_hash']);
            $this->respond(['data' => $profile]);
        }

        $this->apiLibrary()->require_method('POST');
        $input = array_merge($_POST, $this->input());
        $fullName = $this->text($input['full_name'] ?? '', 'Full name', 150);
        $email = strtolower($this->text($input['email'] ?? '', 'Email', 255));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(['error' => 'A valid email is required.'], 422);
        }
        $phone = $this->text($input['phone'] ?? '', 'Phone', 30, false);
        $profilePhoto = null;
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $profilePhoto = $this->saveProfilePhoto($_FILES['profile_photo']);
        }

        $data = ['full_name' => $fullName, 'email' => $email, 'phone' => $phone];
        if ($profilePhoto !== null) {
            $data['profile_photo'] = $profilePhoto;
        }
        try {
            $this->User_model->updateAdminProfile($user['user_id'], $data);
        } catch (PDOException $exception) {
            if ($profilePhoto !== null) {
                @unlink(ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $profilePhoto));
            }
            if ($exception->getCode() === '23000') {
                $this->respond(['error' => 'That email address is already in use.'], 409);
            }
            throw $exception;
        }
        if ($profilePhoto !== null && !empty($profile['profile_photo']) && $profile['profile_photo'] !== $profilePhoto
            && preg_match('~\Auploads/profiles/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $profile['profile_photo'])) {
            $oldPhotoPath = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $profile['profile_photo']);
            if (is_file($oldPhotoPath) && !unlink($oldPhotoPath)) {
                error_log('Could not remove a replaced SakayLokal profile photo.');
            }
        }
        $updated = $this->User_model->findActiveById($user['user_id']);
        unset($updated['password_hash']);
        $this->respond(['data' => $updated]);
    }

    public function account()
    {
        $user = $this->requireAuthenticatedUser();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            unset($user['password_hash']);
            $this->respond(['data' => $user]);
        }

        $this->apiLibrary()->require_method('POST');
        $input = array_merge($_POST, $this->input());
        $fullName = $this->text($input['full_name'] ?? '', 'Full name', 150);
        $email = strtolower($this->text($input['email'] ?? '', 'Email', 255));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->respond(['error' => 'A valid email is required.'], 422);
        }
        $phone = $this->text($input['phone'] ?? '', 'Phone', 30, false);
        $profilePhoto = null;
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $profilePhoto = $this->saveProfilePhoto($_FILES['profile_photo']);
        }

        $data = [
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'notify_advisories' => filter_var($input['notify_advisories'] ?? '0', FILTER_VALIDATE_BOOLEAN),
            'notify_weather' => filter_var($input['notify_weather'] ?? '0', FILTER_VALIDATE_BOOLEAN),
        ];
        if ($profilePhoto !== null) {
            $data['profile_photo'] = $profilePhoto;
        }
        try {
            $this->User_model->updateProfile($user['user_id'], $data);
        } catch (PDOException $exception) {
            if ($profilePhoto !== null) {
                @unlink(ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $profilePhoto));
            }
            if ($exception->getCode() === '23000') {
                $this->respond(['error' => 'That email address is already in use.'], 409);
            }
            throw $exception;
        }
        if ($profilePhoto !== null && !empty($user['profile_photo']) && $user['profile_photo'] !== $profilePhoto
            && preg_match('~\Auploads/profiles/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $user['profile_photo'])) {
            $oldPhotoPath = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $user['profile_photo']);
            if (is_file($oldPhotoPath) && !unlink($oldPhotoPath)) {
                error_log('Could not remove a replaced SakayLokal profile photo.');
            }
        }
        $updated = $this->User_model->findActiveById($user['user_id']);
        unset($updated['password_hash']);
        $this->respond(['data' => $updated]);
    }

    public function reports()
    {
        $user = $this->requireAuthenticatedUser();
        $this->apiLibrary()->require_method('GET');
        $this->call->model('Passenger_report_model');
        $this->respond(['data' => $this->Passenger_report_model->getForUser($user['user_id'])]);
    }

    private function saveProfilePhoto(array $upload)
    {
        if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 2 * 1024 * 1024) {
            $this->respond(['error' => 'Profile photos must be valid images no larger than 2 MB.'], 422);
        }
        $image = @getimagesize($upload['tmp_name']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$image || $image[0] > 4096 || $image[1] > 4096 || !isset($extensions[$mime])) {
            $this->respond(['error' => 'Upload a JPEG, PNG, or WebP profile photo.'], 422);
        }
        $directory = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profiles' . DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the profile-photo upload directory.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($upload['tmp_name'], $directory . $filename)) {
            throw new RuntimeException('Could not store the uploaded profile photo.');
        }
        return 'uploads/profiles/' . $filename;
    }
}
