<?php

declare(strict_types=1);

namespace Featureflip\OpenFeature\Tests;

use Featureflip\Config;
use Featureflip\FeatureflipClient;
use Featureflip\OpenFeature\FeatureflipProvider;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Requests made through a provider built with FeatureflipProvider::create() name it
 * after the SDK (#4152, contract in #4138):
 * "featureflip-php/<v> featureflip-openfeature-php/<v>", so OpenFeature usage can be
 * counted apart from direct SDK use. Asserted on the wire.
 */
final class IdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        FeatureflipClient::resetForTesting();
    }

    public function testCreateTagsTheClientItBuilds(): void
    {
        $transport = new UserAgentRecorder();
        $provider = FeatureflipProvider::create('identity-key-1', $this->config($transport));

        $provider->resolveBooleanValue('any-flag', false);

        $this->assertMatchesRegularExpression('#^featureflip-php/\S+ featureflip-openfeature-php/' . preg_quote(FeatureflipProvider::VERSION, '#') . '$#', $transport->agents[0]);
    }

    public function testTheCallersConfigIsLeftUntouched(): void
    {
        // A new Config is built; the caller's may back direct SDK clients too.
        $config = $this->config(new UserAgentRecorder());

        FeatureflipProvider::create('identity-key-2', $config);

        $this->assertNull($config->wrapperName);
    }

    public function testAWrapperTheCallerAlreadySetIsKept(): void
    {
        // The SDK carries one wrapper token, and the caller's is the more specific.
        $transport = new UserAgentRecorder();
        $provider = FeatureflipProvider::create('identity-key-3', $this->config($transport, 'acme-platform', '3.1'));

        $provider->resolveBooleanValue('any-flag', false);

        $this->assertStringEndsWith(' acme-platform/3.1', $transport->agents[0]);
    }

    private function config(UserAgentRecorder $transport, ?string $wrapperName = null, ?string $wrapperVersion = null): Config
    {
        $factory = new HttpFactory();

        return new Config(
            baseUrl: 'http://eval.test',
            cache: new Psr16Cache(new ArrayAdapter()),
            httpClient: $transport->withFactory($factory),
            requestFactory: $factory,
            streamFactory: $factory,
            wrapperName: $wrapperName,
            wrapperVersion: $wrapperVersion,
        );
    }
}

final class UserAgentRecorder implements ClientInterface
{
    /** @var list<string> */
    public array $agents = [];
    private HttpFactory $factory;

    public function withFactory(HttpFactory $factory): self
    {
        $this->factory = $factory;
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->agents[] = $request->getHeaderLine('User-Agent');

        return $this->factory->createResponse(200)
            ->withBody($this->factory->createStream('{"environment":"e","version":1,"flags":[],"segments":[]}'));
    }
}
