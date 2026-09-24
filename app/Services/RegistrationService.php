<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\ValidationException;
use App\Core\Validator;
use App\Domain\Enums\ConsentType;
use App\Domain\Enums\NotificationCategory;
use App\Domain\Enums\NotificationChannel;
use App\Domain\Enums\TokenPurpose;
use App\Repositories\ConsentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\TokenRepository;
use App\Repositories\UserRepository;

/**
 * Creating accounts: customers, and sellers applying to trade.
 *
 * Both run in one transaction. A half-created account - a user row with no
 * role, or a seller application with no user - is the kind of thing that is
 * discovered weeks later by somebody who cannot log in and cannot be helped.
 *
 * Consent is recorded here, at the moment it is given, with its source. It is
 * never inferred later from the fact that an account exists.
 */
final class RegistrationService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly TokenRepository $tokens = new TokenRepository(),
        private readonly ConsentRepository $consent = new ConsentRepository(),
        private readonly NotificationRepository $notifications = new NotificationRepository(),
    ) {
    }

    /**
     * Registers a customer.
     *
     * @param  array<string,mixed> $input raw request data
     * @return array{userId:int,verificationToken:string}
     * @throws ValidationException with per-field messages
     */
    public function registerCustomer(array $input, string $ip = ''): array
    {
        $data = $this->validateAccountFields($input);

        $this->assertEmailIsFree($data['email']);
        (new AuthService())->assertPasswordAcceptable($data['password'], $data['email']);

        // The terms checkbox is not optional and is not a formality - it is the
        // thing that makes the consent record meaningful.
        if (empty($input['accept_terms'])) {
            throw ValidationException::forField('accept_terms', 'Accept the terms to create an account.');
        }

        return Database::transaction(function () use ($data, $input, $ip): array {
            $userId = $this->users->create([
                'email'         => $data['email'],
                'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
                'first_name'    => $data['first_name'],
                'last_name'     => $data['last_name'],
                'phone'         => $data['phone'],
                'status'        => 'pending_verification',
                'locale'        => 'en',
            ]);

            $this->users->assignRole($userId, 'customer');

            $this->consent->record($userId, ConsentType::Terms, true, 'registration', $ip);
            $this->consent->record($userId, ConsentType::Privacy, true, 'registration', $ip);

            // Marketing is separate and defaults to NO. A customer who does not
            // tick the box gets a granted = 0 row, so "they never agreed" is a
            // fact on file rather than the absence of one.
            $marketing = !empty($input['accept_marketing']);
            $this->consent->record($userId, ConsentType::Marketing, $marketing, 'registration', $ip);

            $this->applyDefaultPreferences($userId, $marketing);

            $token = $this->tokens->issue(
                $userId,
                TokenPurpose::EmailVerification,
                (int) Config::get('security.verify_token_hours', 48) * 60,
                $ip
            );

            $this->notifications->queue(
                $userId,
                NotificationChannel::Email,
                NotificationCategory::OrderUpdates,
                'auth.verify_email',
                ['first_name' => $data['first_name'], 'token' => $token],
                false,
                'user',
                $userId
            );

            Audit::record('user.registered', 'user', $userId, 'Customer account created');

            return ['userId' => $userId, 'verificationToken' => $token];
        });
    }

    /**
     * Registers a seller application.
     *
     * The account is created immediately so the applicant can sign in and track
     * the decision, but with status `pending_approval` and no `sellers` row.
     * There is nothing to trade with until an administrator approves it, which
     * is what SellerService checks - not the presence of the seller role.
     *
     * @param  array<string,mixed> $input
     * @return array{userId:int,applicationId:int,verificationToken:string}
     */
    public function registerSeller(array $input, string $ip = ''): array
    {
        $account = $this->validateAccountFields($input);

        $business = Validator::make($input, [
            'business_name'   => 'required|max:160',
            'business_type'   => 'required|in:sole_trader,partnership,company,cooperative',
            'registration_number' => 'nullable|max:80',
            'region'          => 'required|max:80',
            'district'        => 'required|max:80',
            'store_name'      => 'required|max:160',
            'street'          => 'required|max:190',
            'categories_text' => 'required|max:1000',
        ], [
            'business_name'   => 'Business name',
            'business_type'   => 'Business type',
            'store_name'      => 'Store name',
            'categories_text' => 'What you sell',
        ]);

        if ($business->fails()) {
            throw new ValidationException($business->errors());
        }

        $this->assertEmailIsFree($account['email']);
        (new AuthService())->assertPasswordAcceptable($account['password'], $account['email']);

        if (empty($input['accept_terms'])) {
            throw ValidationException::forField('accept_terms', 'Accept the seller terms to apply.');
        }

        $offersPickup   = !empty($input['offers_pickup']);
        $offersDelivery = !empty($input['offers_delivery']);

        if (!$offersPickup && !$offersDelivery) {
            throw ValidationException::forField(
                'offers_pickup',
                'Choose at least one way customers can receive their orders.'
            );
        }

        $fields = $business->validated();

        return Database::transaction(function () use ($account, $fields, $input, $ip, $offersPickup, $offersDelivery): array {
            $userId = $this->users->create([
                'email'         => $account['email'],
                'password_hash' => password_hash($account['password'], PASSWORD_BCRYPT),
                'first_name'    => $account['first_name'],
                'last_name'     => $account['last_name'],
                'phone'         => $account['phone'],
                'status'        => 'pending_approval',
                'locale'        => 'en',
            ]);

            // The seller role is granted now so the dashboard can be reached,
            // but it grants nothing that matters until `sellers.status` is
            // active. Role and capability are separate on purpose.
            $this->users->assignRole($userId, 'seller');

            $applicationId = Database::insert('seller_applications', [
                'user_id'             => $userId,
                'business_name'       => $fields['business_name'],
                'business_type'       => $fields['business_type'],
                'registration_number' => $fields['registration_number'] ?? null,
                'contact_name'        => $account['first_name'] . ' ' . $account['last_name'],
                'contact_phone'       => $account['phone'],
                'region'              => $fields['region'],
                'district'            => $fields['district'],
                'store_name'          => $fields['store_name'],
                'street'              => $fields['street'],
                'offers_pickup'       => $offersPickup ? 1 : 0,
                'offers_delivery'     => $offersDelivery ? 1 : 0,
                'categories_text'     => $fields['categories_text'],
                'status'              => 'pending_approval',
            ]);

            $this->consent->record($userId, ConsentType::Terms, true, 'seller_application', $ip);
            $this->consent->record($userId, ConsentType::Privacy, true, 'seller_application', $ip);
            $this->consent->record($userId, ConsentType::Marketing, !empty($input['accept_marketing']), 'seller_application', $ip);

            $this->applyDefaultPreferences($userId, !empty($input['accept_marketing']));

            $token = $this->tokens->issue(
                $userId,
                TokenPurpose::EmailVerification,
                (int) Config::get('security.verify_token_hours', 48) * 60,
                $ip
            );

            $this->notifications->queue(
                $userId,
                NotificationChannel::Email,
                NotificationCategory::OrderUpdates,
                'seller.application_received',
                ['business_name' => $fields['business_name'], 'token' => $token],
                false,
                'seller_application',
                $applicationId
            );

            Audit::record(
                'seller.application.submitted',
                'seller_application',
                $applicationId,
                'Application from ' . $fields['business_name']
            );

            return ['userId' => $userId, 'applicationId' => $applicationId, 'verificationToken' => $token];
        });
    }

    /**
     * Completes email verification.
     *
     * The token is consumed inside the same transaction that flips the status,
     * so a link clicked twice in quick succession cannot verify twice.
     */
    public function verifyEmail(string $token): int
    {
        return Database::transaction(function () use ($token): int {
            $userId = $this->tokens->consume(TokenPurpose::EmailVerification, $token);

            if ($userId === null) {
                throw new DomainRuleException(
                    'That verification link is no longer valid. Request a new one.',
                    'invalid_token'
                );
            }

            $this->users->markEmailVerified($userId);

            Audit::record('user.email.verified', 'user', $userId, 'Email address confirmed');

            return $userId;
        });
    }

    /**
     * Re-issues a verification link, with its own limit. "Resend" is a way to
     * make our mail server post letters to an address the requester chose, so
     * it needs a cap of its own.
     */
    public function resendVerification(int $userId, string $ip = ''): string
    {
        $user = $this->users->find($userId);

        if ($user === null) {
            throw new DomainRuleException('That account no longer exists.', 'unknown_user');
        }

        if ($user['email_verified_at'] !== null) {
            throw new DomainRuleException('That address is already confirmed.', 'already_verified');
        }

        if ($this->tokens->issuedSince($userId, TokenPurpose::EmailVerification, 10) >= 3) {
            throw new DomainRuleException(
                'We have sent several confirmation emails recently. Check your inbox, including spam, before asking for another.',
                'throttled'
            );
        }

        $token = $this->tokens->issue(
            $userId,
            TokenPurpose::EmailVerification,
            (int) Config::get('security.verify_token_hours', 48) * 60,
            $ip
        );

        $this->notifications->queue(
            $userId,
            NotificationChannel::Email,
            NotificationCategory::OrderUpdates,
            'auth.verify_email',
            ['first_name' => $user['first_name'], 'token' => $token],
            false,
            'user',
            $userId
        );

        return $token;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array{email:string,password:string,first_name:string,last_name:string,phone:string}
     */
    private function validateAccountFields(array $input): array
    {
        $v = Validator::make($input, [
            'first_name' => 'required|max:80',
            'last_name'  => 'required|max:80',
            'email'      => 'required|email|max:190',
            'phone'      => 'required|phone|max:32',
            'password'   => 'required|min:10|confirmed',
        ], [
            'first_name' => 'First name',
            'last_name'  => 'Last name',
            'email'      => 'Email address',
            'phone'      => 'Phone number',
            'password'   => 'Password',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }

        $clean = $v->validated();

        return [
            'email'      => mb_strtolower((string) $clean['email']),
            'password'   => (string) $clean['password'],
            'first_name' => (string) $clean['first_name'],
            'last_name'  => (string) $clean['last_name'],
            'phone'      => (string) $clean['phone'],
        ];
    }

    private function assertEmailIsFree(string $email): void
    {
        if ($this->users->emailExists($email)) {
            // Saying "that address is taken" does confirm an account exists.
            // It is the lesser problem: the alternative is a registration form
            // that appears to succeed and sends nothing, which strands a real
            // person with no way forward.
            throw ValidationException::forField(
                'email',
                'An account already uses that email address. Sign in, or reset your password.'
            );
        }
    }

    /**
     * Transactional categories on by email; offers and reorder reminders follow
     * what the customer chose. SMS and WhatsApp stay off because no provider is
     * connected - switching them on would promise something we cannot deliver.
     */
    private function applyDefaultPreferences(int $userId, bool $marketing): void
    {
        foreach ([NotificationCategory::OrderUpdates, NotificationCategory::PickupDelivery, NotificationCategory::Support] as $category) {
            $this->consent->setChannel($userId, $category, NotificationChannel::Email, true);
        }

        $this->consent->setChannel($userId, NotificationCategory::Reorder, NotificationChannel::Email, $marketing);
        $this->consent->setChannel($userId, NotificationCategory::Offers, NotificationChannel::Email, $marketing);
    }
}
