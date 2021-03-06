<?php

declare(strict_types=1);

namespace ApiClients\Tests\Tools\Psr7\Oauth1\RequestSigning;

use ApiClients\Tools\Psr7\Oauth1\Definition\AccessToken;
use ApiClients\Tools\Psr7\Oauth1\Definition\ConsumerKey;
use ApiClients\Tools\Psr7\Oauth1\Definition\ConsumerSecret;
use ApiClients\Tools\Psr7\Oauth1\Definition\TokenSecret;
use ApiClients\Tools\Psr7\Oauth1\RequestSigning\RequestSigner;
use ApiClients\Tools\Psr7\Oauth1\Signature\HmacSha1Signature;
use ApiClients\Tools\Psr7\Oauth1\Signature\HmacSha512Signature;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function array_merge;
use function count;
use function explode;
use function rawurldecode;
use function str_replace;
use function strlen;

final class RequestSignerTest extends TestCase
{
    public function testImmutability(): void
    {
        $requestSigner                = new RequestSigner(
            new ConsumerKey('consumer_key'),
            new ConsumerSecret('consumer_secret')
        );
        $requestSignerWithAccessToken = $requestSigner->withAccessToken(
            new AccessToken('access_token'),
            new TokenSecret('token_secret')
        );
        self::assertNotSame($requestSigner, $requestSignerWithAccessToken);
        $requestSignerWithAccessTokenWithoutAccessToken = $requestSignerWithAccessToken->withoutAccessToken();
        self::assertNotSame($requestSigner, $requestSignerWithAccessTokenWithoutAccessToken);
        self::assertNotSame($requestSignerWithAccessToken, $requestSignerWithAccessTokenWithoutAccessToken);
        self::assertEquals($requestSigner, $requestSignerWithAccessTokenWithoutAccessToken);
    }

    public function testSign(): void
    {
        $expectedHeaderParts = [
            'oauth_consumer_key' => false,
            'oauth_nonce' => false,
            'oauth_signature_method' => false,
            'oauth_timestamp' => false,
            'oauth_version' => false,
            'oauth_token' => false,
            'oauth_signature' => false,
        ];
        $captureValues       = [
            'oauth_consumer_key' => '',
            'oauth_nonce' => '',
            'oauth_signature_method' => '',
            'oauth_timestamp' => '',
            'oauth_version' => '',
            'oauth_token' => '',
            'oauth_signature' => '',
        ];
        $request             = new Request(
            'POST',
            'httpx://example.com/',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'foo=bar&bar=baz'
        );
        $requestSigner       = (new RequestSigner(
            new ConsumerKey('consumer_key'),
            new ConsumerSecret('consumer_secret'),
            new HmacSha512Signature(
                new ConsumerSecret('consumer_secret')
            )
        ))->withAccessToken(
            new AccessToken('access_token'),
            new TokenSecret('token_secret')
        );

        $signedRequest = $requestSigner->sign($request);

        self::assertNotSame($request, $signedRequest);
        self::assertTrue($signedRequest->hasHeader('Authorization'));
        $headerChunks = explode(' ', $signedRequest->getHeader('Authorization')[0]);
        self::assertCount(2, $headerChunks);
        self::assertSame('OAuth', $headerChunks[0]);

        $headerChunks = explode(',', $headerChunks[1]);
        self::assertCount(count($expectedHeaderParts), $headerChunks);
        foreach ($headerChunks as $headerChunk) {
            [$key, $value] = explode('=', $headerChunk);
            self::assertArrayHasKey($key, $expectedHeaderParts);
            if (array_key_exists($key, $captureValues)) {
                $captureValues[$key] = rawurldecode(str_replace('"', '', $value));
            }

            $expectedHeaderParts[$key] = true;
        }

        foreach ($expectedHeaderParts as $expectedHeaderPart) {
            self::assertTrue($expectedHeaderPart);
        }

        $signature = $captureValues['oauth_signature'];
        unset($captureValues['oauth_signature']);

        self::assertSame(
            (new HmacSha512Signature(
                new ConsumerSecret('consumer_secret')
            ))->withTokenSecret(
                new TokenSecret('token_secret')
            )->sign(
                $request->getUri(),
                array_merge(['foo' => 'bar', 'bar' => 'baz'], $captureValues),
                'POST'
            ),
            $signature
        );
    }

    public function testSignToRequestAuthorization(): void
    {
        $callbackUri         = 'https://example.com/callback';
        $expectedHeaderParts = [
            'oauth_consumer_key' => false,
            'oauth_nonce' => false,
            'oauth_signature_method' => false,
            'oauth_timestamp' => false,
            'oauth_version' => false,
            'oauth_callback' => false,
            'oauth_signature' => false,
            'extra_test_to_make_sure_this_is_included' => true,
        ];
        $captureValues       = [
            'oauth_consumer_key' => '',
            'oauth_nonce' => '',
            'oauth_signature_method' => '',
            'oauth_timestamp' => '',
            'oauth_version' => '',
            'oauth_callback' => '',
            'oauth_signature' => '',
            'extra_test_to_make_sure_this_is_included' => '',
        ];
        $request             = new Request(
            'POST',
            'httpx://example.com/',
            [],
            'foo=bar&bar=baz'
        );
        $requestSigner       = new RequestSigner(
            new ConsumerKey('consumer_key'),
            new ConsumerSecret('consumer_secret')
        );

        $signedRequest = $requestSigner->signToRequestAuthorization($request, $callbackUri, ['extra_test_to_make_sure_this_is_included' => 'Yay!']);

        self::assertNotSame($request, $signedRequest);
        self::assertTrue($signedRequest->hasHeader('Authorization'));
        $headerChunks = explode(' ', $signedRequest->getHeader('Authorization')[0]);
        self::assertCount(2, $headerChunks);
        self::assertSame('OAuth', $headerChunks[0]);

        $headerChunks = explode(',', $headerChunks[1]);
        self::assertCount(count($expectedHeaderParts), $headerChunks);
        foreach ($headerChunks as $headerChunk) {
            [$key, $value] = explode('=', $headerChunk);
            self::assertArrayHasKey($key, $expectedHeaderParts);
            if (array_key_exists($key, $captureValues)) {
                $captureValues[$key] = rawurldecode(str_replace('"', '', $value));
            }

            $expectedHeaderParts[$key] = true;
        }

        foreach ($expectedHeaderParts as $expectedHeaderPart) {
            self::assertTrue($expectedHeaderPart);
        }

        foreach ($captureValues as $captureValue) {
            self::assertGreaterThan(0, strlen($captureValue));
        }

        self::assertSame(32, strlen($captureValues['oauth_nonce']));
        self::assertSame('Yay!', $captureValues['extra_test_to_make_sure_this_is_included']);

        $signature = $captureValues['oauth_signature'];
        unset($captureValues['oauth_signature']);

        self::assertSame(
            (new HmacSha1Signature(
                new ConsumerSecret('consumer_secret')
            ))->sign(
                $request->getUri(),
                $captureValues,
                'POST'
            ),
            $signature
        );
    }
}
