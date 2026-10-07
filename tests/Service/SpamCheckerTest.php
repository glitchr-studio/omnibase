<?php

namespace Tests\Base\Service;

use Base\Entity\Thread\Comment;
use Base\Enum\CommentState;
use Base\Enum\SpamScore;
use Base\Form\Model\CommentModel;
use Base\Routing\AdvancedRouterInterface;
use Base\Service\FormGuard;
use Base\Service\ParameterBagInterface;
use Base\Service\SettingBagInterface;
use Base\Service\SpamChecker;
use Base\Service\TranslatorInterface;
use Omniguard\Akismet\AkismetGatewayFactory;
use Omniguard\Model\Submission;
use Omniguard\Registry;
use Omniguard\Testing\FixedGateway;
use Omniguard\Testing\FixedGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SpamChecker on glitchr/omniguard's classifier: a gateway named by
 * base.guard.classifier, else Akismet with the site's key - what it is told
 * (texts, the site's home page, a date), what its answer becomes (a comment
 * kept aside as SPAM), and what happens when it does not answer.
 */
class SpamCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Registry::class) || !class_exists(AkismetGatewayFactory::class)) {
            self::markTestSkipped('glitchr/omniguard and omniguard/akismet are not installed.');
        }
    }

    /** @param array<string, mixed> $guard base.guard */
    private function checker(array $guard = [], ?Registry $registry = null, ?string $key = null, ?HttpClientInterface $http = null): SpamChecker
    {
        $requests = new RequestStack();
        $requests->push(Request::create('https://site.example/blog/un-billet', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Firefox', 'HTTP_REFERER' => 'https://site.example/blog']));

        $settings = $this->createMock(SettingBagInterface::class);
        $settings->method('getScalar')->willReturn($key);
        $parameters = $this->createMock(ParameterBagInterface::class);
        $parameters->method('get')->willReturnCallback(fn (string $name) => 'kernel.default_locale' === $name ? 'fr' : null);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('fr');
        $translator->method('getFallbackLocales')->willReturn(['fr']);
        $router = $this->createMock(AdvancedRouterInterface::class);
        $router->method('getUrlIndex')->willReturn('/');

        return new SpamChecker($requests, $settings, $parameters, $translator, $http ?? new MockHttpClient(), false, new FormGuard('secret', $guard, $registry), $router);
    }

    private function comment(string $text = 'Un commentaire.'): CommentModel
    {
        $model = new CommentModel();
        $model->name = 'Anne Leroy';
        $model->email = 'anne@example.org';
        $model->content = $text;

        return $model;
    }

    public function testWithoutAClassifierNothingIsAsked(): void
    {
        $this->assertSame(SpamScore::__toInt()[SpamScore::NOT_SPAM], $this->checker()->check($this->comment()));
        $this->assertSame(SpamScore::__toInt()[SpamScore::NO_TEXT], $this->checker()->score($this->comment('')));
        $this->assertFalse($this->checker()->report(new Submission('x'), true), 'nobody to tell');
    }

    public function testContentClassifiedAsSpamKeepsTheCommentAside(): void
    {
        $registry = new Registry([new FixedGatewayFactory()], ['comments' => ['factory' => 'fixed', 'options' => ['pass' => false]]]);
        $model = $this->comment('Achetez maintenant');

        $this->assertSame(SpamScore::__toInt()[SpamScore::MAYBE_SPAM], $this->checker(['classifier' => 'comments'], $registry)->check($model));
        $this->assertSame(CommentState::SPAM, $model->toComment(null, true)->getState(), 'kept aside, for a person to read');

        $ham = $this->comment();
        $this->checker(['classifier' => 'comments'], new Registry([new FixedGatewayFactory()], ['comments' => ['factory' => 'fixed']]))->check($ham);
        $this->assertSame(CommentState::APPROVED, $ham->toComment(null, true)->getState());
    }

    public function testAClassifierThatDoesNotAnswerIsTheConductConfigured(): void
    {
        $registry = new Registry([new DownFactory()], ['comments' => ['factory' => 'down']]);
        $scores = SpamScore::__toInt();

        $this->assertSame($scores[SpamScore::NOT_SPAM], $this->checker(['classifier' => 'comments', 'unreachable' => 'accept'], $registry)->score($this->comment()));
        $this->assertSame($scores[SpamScore::MAYBE_SPAM], $this->checker(['classifier' => 'comments', 'unreachable' => 'reject'], $registry)->score($this->comment()), 'held for a person');
    }

    public function testAkismetIsToldTextsTheSitesHomePageAndADate(): void
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
            parse_str($options['body'], $fields);
            $sent[] = [$url, $fields];

            return new MockResponse('true', ['response_headers' => ['x-akismet-pro-tip: discard']]);
        });

        $score = $this->checker(key: 'the-site-key', http: $http)->score($this->comment('Visitez mon site'));

        $this->assertSame(SpamScore::__toInt()[SpamScore::BLATANT_SPAM], $score, 'discard: blatant spam');
        [$url, $fields] = $sent[0];
        $this->assertSame('https://rest.akismet.com/1.1/comment-check', $url, 'the key as a parameter, not in the host');
        $this->assertSame('the-site-key', $fields['api_key']);
        $this->assertSame('https://site.example/', $fields['blog'], 'the home page, not the page the form is on');
        $this->assertSame('https://site.example/blog/un-billet', $fields['permalink']);
        $this->assertSame('Anne Leroy', $fields['comment_author'], 'a name, as text');
        $this->assertSame('anne@example.org', $fields['comment_author_email']);
        $this->assertSame('203.0.113.7', $fields['user_ip']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $fields['comment_date_gmt'], 'a date, as text');
        $this->assertSame('fr', $fields['blog_lang']);
    }

    public function testAKeyAkismetRefusesIsLetThroughNotTakenForTheVisitors(): void
    {
        $http = new MockHttpClient(new MockResponse('invalid', ['response_headers' => ['x-akismet-debug-help: Empty "api_key" value']]));

        $this->assertSame(SpamScore::__toInt()[SpamScore::NOT_SPAM], $this->checker(key: 'a-wrong-key', http: $http)->score($this->comment()));
    }

    public function testAReportGoesToTheSameClassifier(): void
    {
        $registry = new Registry([new FixedGatewayFactory()], ['comments' => ['factory' => 'fixed']]);
        $checker = $this->checker(['classifier' => 'comments'], $registry);
        $submission = $checker->submission(new Comment(), ['comment_author' => 'Anne']);

        $this->assertTrue($checker->report($submission, true));
        $gateway = $registry->classifier('comments');
        $this->assertInstanceOf(FixedGateway::class, $gateway);
        $this->assertSame([[$submission, true]], $gateway->reports);
        $this->assertSame('Anne', $submission->author);
        $this->assertSame('https://site.example/', $submission->site);
    }
}
