<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;

if (!function_exists('sendVerifyEmail')) {
    /**
     * Gửi email xác minh cho user
     *
     * @param string $email
     * @return array|null
     */
    function sendVerifyEmail(string $email)
    {
        $user = User::where('email', $email)
            ->whereNull('email_verified_at')
            ->first();

        if (!$user) {
            return [
                'status' => 'error',
                'message' => 'Không tìm thấy user với email này hoặc email đã được xác minh.'
            ];
        }

        try {
            Mail::mailer('smtp')->to($user->email)
                ->send(new class($user) extends Mailable {
                    public $user;
                    public $url;

                    public function __construct($user)
                    {
                        $this->user = $user;
                        $this->url = URL::temporarySignedRoute(
                            'verification.verify',
                            now()->addMinutes(60),
                            ['id' => $user->id, 'hash' => sha1($user->email)],
                            true
                        );
                    }

                    public function build()
                    {
                        return $this->subject('Xác minh email của bạn')
                            ->html("
                        <p>Xin chào <strong>{$this->user->name}</strong>,</p>
                        <p>Nhấn vào nút bên dưới để xác minh email:</p>
                        <p><a href='{$this->url}' style='padding:10px 20px; background-color:#3490dc; color:#fff; text-decoration:none;'>Xác minh email</a></p>
                        <p>Nếu bạn không đăng ký tài khoản, vui lòng bỏ qua email này.</p>
                    ");
                    }
                });
            Log::info("Mail xác minh đã gửi tới {$user->email}");
        } catch (Exception $e) {
            Log::error("Gửi mail thất bại: " . $e->getMessage());
        }


        return [
            'status' => 'success',
            'message' => 'Mail xác minh đã được gửi tới ' . $user->email
        ];
    }
}