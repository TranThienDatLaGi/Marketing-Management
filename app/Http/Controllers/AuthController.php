<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                "message" => "Email chưa được dùng để đăng ký",
                "error_code" => "EMAIL_NOT_FOUND",
            ], 401);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                "message" => "Mật khẩu không khớp với email",
                "error_code" => "WRONG_PASSWORD",
            ], 401);
        }
        if (!$user->email_verified_at) {
            return response()->json([
                "message" => "Tài khoản đã được đăng kí nhưng chưa verify",
                "error_code" => "NOT_VERIFY",
            ], 401);
        }
        if ($user->status === "inactive") {
            return response()->json([
                "message" => "Tài khoản đã bị vô hiệu hóa",
                "error_code" => "INACTIVE",
            ], 401);
        }

        // Tạo token Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            "message" => "Đăng nhập thành công",
            "access_token" => $token,
            "token_type" => "Bearer",
            "user" => [
                "id" => $user->id,
                "name" => $user->name,
                "email" => $user->email,
                "role" => $user->role,
                "status" => $user->status,
            ],
        ], 200);
    }
    public function logout(Request $request)
    {
        if ($request->user()) {
            $request->user()->currentAccessToken()->delete();
            return response()->json([
                "message" => "Tài khoản đăng xuất thành công"
            ]);
        }
        return response()->json([
            "message" => "Không tìm  user hoặc chưa đăng nhập"
        ]);
    }
    public function getListUser(Request $request)
    {
        if ($request->user()->role === "admin") {
            $users = User::all()->makeHidden('password');
            return response()->json([
                "message" => "Lấy danh sách tài khoản thành công",
                "list_user" => $users,
            ], 200);
        } else {
            return response()->json([
                "message" => "Bạn không có quyền hạn này",
            ], 401);
        }
    }
    public function register(RegisterRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if ($user) {
            if ($user->email_verified_at) {
                return response()->json([
                    "message" => "Email đã được dùng để đăng ký.",
                    "error_code" => "EMAIL_ALREADY_VERIFIED",
                ], 409);
            }

            $user->update([
                'name' => $request->name,
                'password' => Hash::make($request->password),
                'role' => $request->role,
            ]);

            // Gọi helper đã test thành công
            $mailResult = sendVerifyEmail($request->email);

            return response()->json([
                'message' => 'Tài khoản đã tồn tại nhưng chưa xác nhận. Hệ thống đã gửi lại email xác nhận.',
                'user' => $user,
                'mail' => $mailResult
            ], 200);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        $mailResult = sendVerifyEmail($request->email);

        return response()->json([
            'message' => 'Đăng ký thành công. Vui lòng kiểm tra email để xác nhận tài khoản.',
            'user' => $user,
            'mail' => $mailResult
        ], 201);
    }

    public function sendResetLink(ForgotPasswordRequest $request)
    {
        $email = $request->email;

        // Kiểm tra xem người dùng có tồn tại không
        $user = DB::table('users')->where('email', $email)->first();
        if (!$user) {
            return response()->json(['message' => 'Email không tồn tại trong hệ thống'], 404);
        }

        // Tạo mật khẩu ngẫu nhiên (ít nhất 8 ký tự, có hoa, thường, số, ký tự đặc biệt)
        $password = $this->generateStrongPassword(10); // bạn có thể đổi 10 thành 12, 14 tuỳ thích

        // Cập nhật lại mật khẩu đã mã hoá
        DB::table('users')
            ->where('email', $email)
            ->update([
                'password' => Hash::make($password),
                'updated_at' => now()
            ]);

        // Gửi mail báo mật khẩu mới
        Mail::raw("Mật khẩu mới của bạn là: {$password}\n\nVui lòng đăng nhập và thay đổi lại mật khẩu sau khi vào hệ thống.", function ($message) use ($email) {
            $message->to($email);
            $message->subject('Mật khẩu mới của bạn');
        });

        return response()->json(['message' => 'Mật khẩu mới đã được gửi đến email của bạn']);
    }
    public function resetPassword(ResetPasswordRequest $request)
    {
        // Lấy bản ghi password_resets ứng với email và token
        $record = DB::table('password_resets')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        // Kiểm tra token
        if (!$record) {
            return response()->json(['message' => 'Token không hợp lệ'], 400);
        }

        // Kiểm tra thời gian hết hạn (5 phút)
        if (Carbon::parse($record->created_at)->addMinutes(5)->isPast()) {
            return response()->json(['message' => 'Đường link đã hết hạn'], 400);
        }

        // Cập nhật mật khẩu cho user
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'Người dùng không tồn tại'], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Xóa token đã sử dụng
        DB::table('password_resets')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Mật khẩu của bạn đã được cập nhật']);
    }
    public function verifyEmail($id, $hash)
    {
        $user = User::findOrFail($id);

        // Kiểm tra hash có hợp lệ (Laravel mặc định hash email)
        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Link xác nhận không hợp lệ'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email đã được xác nhận'], 200);
        }

        $user->markEmailAsVerified();

        return response()->json(['message' => 'Xác nhận email thành công'], 200);
    }

    /**
     * Hàm tạo mật khẩu mạnh (ít nhất 1 chữ hoa, 1 chữ thường, 1 số, 1 ký tự đặc biệt)
     */
    private function generateStrongPassword($length = 10)
    {
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $symbols = '!@#$%^&*()_-+=<>?';

        // Bắt buộc mỗi loại có ít nhất 1 ký tự
        $password = substr(str_shuffle($upper), 0, 1) .
            substr(str_shuffle($lower), 0, 1) .
            substr(str_shuffle($numbers), 0, 1) .
            substr(str_shuffle($symbols), 0, 1);

        // Thêm các ký tự ngẫu nhiên cho đủ độ dài
        $all = $upper . $lower . $numbers . $symbols;
        $remaining = $length - strlen($password);
        $password .= substr(str_shuffle(str_repeat($all, $remaining)), 0, $remaining);

        // Xáo trộn lại toàn bộ để đảm bảo ngẫu nhiên
        return str_shuffle($password);
    }

    public function changePassword(Request $request)
    {
        $user = User::find($request->id);

        if (!$user) {
            return response()->json(['message' => 'Không tìm thấy người dùng'], 404);
        }

        $new_password = $request->new_password;

        // Kiểm tra độ mạnh mật khẩu
        $pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/';
        if (!preg_match($pattern, $new_password)) {
            return response()->json([
                'message' => 'Mật khẩu phải có ít nhất 8 ký tự, bao gồm chữ hoa, chữ thường, số và ký tự đặc biệt.'
            ], 400);
        }

        $user->password = Hash::make($new_password);
        $user->save();

        return response()->json(['message' => 'Mật khẩu đã được thay đổi thành công']);
    }
    public function updateUser(Request $request)
    {
        $user = User::find($request->id);

        if (!$user) {
            return response()->json(['message' => 'Không tìm thấy người dùng'], 404);
        }
        $user->update([
            'name' => $request->name ?? $user->name,
            'role' => $request->role ?? $user->role,
            'status' => $request->status ?? $user->status,
            'image' => $request->image ?? $user->image,
            'updated_at' => Carbon::now(),
        ]);
        $user->save();

        return response()->json(
            [
                'message' => 'Tài khoản đã được cập nhật thành công',
                "user" => [
                    "id" => $user->id,
                    "name" => $user->name,
                    "email" => $user->email,
                    "role" => $user->role,
                    "status" => $user->status,
                ],
            ],
            200
        );
    }
    public function checkPassword(Request $request)
    {
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'Không tìm thấy người dùng'], 404);
        }
        $password = trim($request->password);
        if (Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Mật khẩu đúng'], 200);
        } else {
            return response()->json(['message' => 'Mật khẩu không đúng'], 400);
        }
    }
    private function sendMail(string $email)
    {
        $user = User::where('email', $email)
            ->whereNull('email_verified_at')
            ->first();

        if (!$user) {
            // Không nên return JSON trực tiếp nếu dùng trong register
            // Có thể ném exception hoặc trả false
            return false;
        }

        // Gửi mail xác minh
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

        return true;
    }
}
