<?php

namespace App\Socialite;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

class MicrosoftProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    protected $tenant = 'common';

    public function setTenant(string $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    protected function getAuthUrl($state): string
    {
        $tenant = $this->config['tenant'] ?? $this->tenant;
        return $this->buildAuthUrlFromBase("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize", $state);
    }

    protected function getTokenUrl(): string
    {
        $tenant = $this->config['tenant'] ?? $this->tenant;
        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
    }

    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get('https://graph.microsoft.com/v1.0/me', [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
            ],
            'query' => [
                '$select' => 'id,displayName,mail,userPrincipalName,employeeType',
            ],
        ]);

        return json_decode($response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => $user['displayName'] ?? null,
            'name' => $user['displayName'] ?? null,
            'email' => $user['mail'] ?? $user['userPrincipalName'] ?? null,
            'avatar' => null,
        ]);
    }

    protected function getTokenFields($code): array
    {
        return array_merge(parent::getTokenFields($code), [
            'grant_type' => 'authorization_code',
        ]);
    }
}
