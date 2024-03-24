<?php

namespace Afsakar\FilamentOtpLogin\Filament\Pages;

use Afsakar\FilamentOtpLogin\Models\OtpCode;
use Afsakar\FilamentOtpLogin\Notifications\SendOtpCode;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Actions\Action as ActionComponent;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class Login extends BaseLogin
{
    use InteractsWithFormActions;
    use Notifiable;
    use WithRateLimiting;

    protected static string $view = 'filament-otp-login::pages.login';

    public ?array $data = [];

    public int $step = 1;

    public int | string $otpCode = '';

    public string $email = '';

    public int $countDown = 120;

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }

        $this->form->fill();

        $this->countDown = config('filament-otp-login.otp_code.expires');
    }

    protected function rateLimiter()
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            Notification::make()
                ->title(__('filament-panels::pages/auth/login.notifications.throttled.title', [
                    'seconds' => $exception->secondsUntilAvailable,
                    'minutes' => ceil($exception->secondsUntilAvailable / 60),
                ]))
                ->body(array_key_exists('body', __('filament-panels::pages/auth/login.notifications.throttled') ?: []) ? __('filament-panels::pages/auth/login.notifications.throttled.body', [
                    'seconds' => $exception->secondsUntilAvailable,
                    'minutes' => ceil($exception->secondsUntilAvailable / 60),
                ]) : null)
                ->danger()
                ->send();

            return null;
        }
    }

    public function authenticate(): ?LoginResponse
    {
        $this->rateLimiter();

        $this->verifyCode();

        $this->doLogin();

        return app(LoginResponse::class);
    }

    protected function doLogin(): void
    {
        $data = $this->form->getState();

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            $this->throwFailureValidationException();
        }

        $user = Filament::auth()->user();

        if (
            ($user instanceof FilamentUser) &&
            (! $user->canAccessPanel(Filament::getCurrentPanel()))
        ) {
            Filament::auth()->logout();

            $this->throwFailureValidationException();
        }

        session()->regenerate();
    }

    public function verifyCode(): void
    {
        $code = OtpCode::where('code', $this->data['otp'])->first();

        if (! $code) {
            throw ValidationException::withMessages([
                'data.otp' => __('filament-otp-login::translations.validation.invalid_code'),
            ]);
        } elseif (! $code->isValid()) {
            throw ValidationException::withMessages([
                'data.otp' => __('filament-otp-login::translations.validation.expired_code'),
            ]);
        } else {
            $this->dispatch('codeVerified');

            $code->delete();
        }
    }

    public function generateCode(): void
    {
        do {
            $length = config('filament-otp-login.otp_code.length');

            $code = str_pad(rand(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        } while (OtpCode::where('code', $code)->exists());

        $this->otpCode = $code;

        $data = $this->form->getState();

        OtpCode::updateOrCreate([
            'email' => $data['email'],
        ], [
            'code' => $this->otpCode,
            'expires_at' => now()->addSeconds(config('filament-otp-login.otp_code.expires')),
        ]);

        $this->dispatch('countDownStarted');
    }

    public function sendOtp(): void
    {
        $this->rateLimiter();

        $data = $this->form->getState();

        $this->checkCredentials($data);

        $this->generateCode();

        $this->sendOtpToUser($this->otpCode);

        $this->step = 2;
    }

    #[On('resendCode')]
    public function resendCode(): void
    {
        $this->rateLimiter();

        $this->generateCode();

        $this->sendOtpToUser($this->otpCode);
    }

    protected function sendOtpToUser(string $otpCode): void
    {
        $this->email = $this->data['email'];

        $this->notify(new SendOtpCode($otpCode));

        Notification::make()
            ->title(__('filament-otp-login::translations.notifications.title'))
            ->body(__('filament-otp-login::translations.notifications.body', ['seconds' => config('filament-otp-login.otp_code.expires')]))
            ->success()
            ->send();
    }

    public function form(Form $form): Form
    {
        return $form;
    }

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getRememberFormComponent(),
                    ])
                    ->statePath('data'),
            ),
            'otpForm' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getOtpCodeFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getOtpCodeFormComponent(): Component
    {
        return TextInput::make('otp')
            ->label(__('filament-otp-login::translations.otp_code'))
            ->numeric()
            ->suffixIcon('heroicon-o-finger-print')
            ->hintAction(fn () => $this->goBackAction())
            ->maxLength(config('filament-otp-login.otp_code.length'))
            ->required()
            ->extraInputAttributes(['tabindex' => 3]);
    }

    /**
     * @return array<Action | ActionGroup>
     */
    public function getFormActions(): array
    {
        return [
            $this->getAuthenticateFormAction(),
        ];
    }

    /**
     * @return array<Action | ActionGroup>
     */
    public function getOtpFormActions(): array
    {
        return [
            $this->getSendOtpAction(),
        ];
    }

    protected function getSendOtpAction(): Action
    {
        return Action::make('send-otp')
            ->label(__('filament-otp-login::translations.view.verify'))
            ->submit('sendOtp');
    }

    protected function goBackAction(): ActionComponent
    {
        return ActionComponent::make('go-back')
            ->label(__('filament-otp-login::translations.view.go_back'))
            ->action(fn () => $this->step = 1);
    }

    protected function getAuthenticateFormAction(): Action
    {
        return Action::make('authenticate')
            ->label(__('filament-panels::pages/auth/login.form.actions.authenticate.label'))
            ->submit('authenticate');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => $data['email'],
            'password' => $data['password'],
        ];
    }

    protected function checkCredentials($data): void
    {
        $user = config('filament-otp-login.user_model')::where('email', $data['email'])->first();

        if (! $user || ! password_verify($data['password'], $user->password)) {
            $this->throwFailureValidationException();
        }
    }
}