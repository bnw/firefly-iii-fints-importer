<?php

use App\Step;
use App\TanHandler;
use Fhp\BaseAction;
use Fhp\FinTs;
use Fhp\Model\TanRequest;
use Fhp\Model\TanMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Named fixture: a TanRequest that survives serialize/unserialize.
 * Anonymous classes can't be reconstructed after unserialize, so we use
 * a concrete named class here.
 */
final class FixtureTanRequest implements TanRequest
{
    public function getProcessId(): string { return 'process-id'; }
    public function getChallenge(): ?string { return 'challenge-text'; }
    public function getTanMediumName(): ?string { return null; }
    public function getChallengeHhdUc(): ?\Fhp\Syntax\Bin { return null; }
}

/**
 * Named fixture BaseAction subclass that:
 *   - has a non-null tanRequest (matches "TAN was asked, not yet confirmed")
 *   - reports needsTan() = true
 *   - is serialize/unserialize friendly (named, no closures)
 */
final class FixturePendingTanAction extends BaseAction
{
    public function __construct()
    {
        $this->tanRequest = new FixtureTanRequest();
    }
    public function needsTan(): bool { return true; }
    protected function createRequest(\Fhp\Protocol\BPD $bpd, ?\Fhp\Protocol\UPD $upd) { return []; }
}

/**
 * Tests TanHandler's decoupled-TAN handling.
 *
 * Bug being fixed: when a decoupled TAN mode (e.g. DKB pushTAN 940) is still
 * pending user confirmation on their phone, bnw's create_or_continue_action()
 * RE-CREATES the action by re-invoking the `create_action_lambda`. That
 * lambda calls $fin_ts->login() which initiates a brand-new dialog with the
 * bank, which sends a brand-new TAN challenge to the user's phone. Repeat
 * this and the bank rate-limits ("Authorization method is locked because
 * too many challenges were requested" — DKB error 9040). The fix is to
 * NOT re-create the action; just keep it, so the caller can re-render the
 * "waiting for TAN confirmation" page.
 *
 * See bnw/firefly-iii-fints-importer#230, #227, #183, and phpFinTS#535's
 * resolution (needsPollingWait()-based pattern).
 */
final class TanHandlerTest extends TestCase
{
    /** Build the boilerplate Session/Twig/Request/Step dependencies that
     * TanHandler needs but doesn't actually exercise in these tests. */
    private function fakeSession(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    private function fakeTwig(): Environment
    {
        // ArrayLoader lets us render anything without filesystem templates.
        return new Environment(new ArrayLoader([]));
    }

    private function fakeRequest(): Request
    {
        return new Request();
    }

    private function fakeStep(): Step
    {
        return new Step(Step::STEP2_LOGIN);
    }

    /**
     * The bug: when we have a previously-saved decoupled action waiting for
     * confirmation, and the user hasn't tapped approve yet, TanHandler used
     * to re-run the action lambda — effectively doing a fresh login() and
     * generating a NEW TAN challenge to the bank.
     *
     * Desired behavior: lambda is NOT called. The pending action stays as
     * the current action and the caller re-renders the waiting UI.
     */
    public function test_decoupled_pending_does_not_recreate_action(): void
    {
        // Named, serialize-safe fixture: TAN asked, user hasn't confirmed.
        $action = new FixturePendingTanAction();

        $session = $this->fakeSession();
        $session->set('login-action', serialize($action));

        // FinTs mock: decoupled TAN mode, checkDecoupledSubmission returns
        // false (user hasn't confirmed yet).
        $tanMode = $this->createMock(TanMode::class);
        $tanMode->method('isDecoupled')->willReturn(true);

        $finTs = $this->createMock(FinTs::class);
        $finTs->method('getSelectedTanMode')->willReturn($tanMode);
        $finTs->method('checkDecoupledSubmission')->willReturn(false);

        // Count lambda invocations. The fix means it must be 0.
        $lambdaCalls = 0;
        $lambda = function () use (&$lambdaCalls, $action) {
            $lambdaCalls++;
            return $action;
        };

        new TanHandler(
            $lambda,
            'login-action',
            $session,
            $this->fakeTwig(),
            $finTs,
            $this->fakeStep(),
            $this->fakeRequest(),
        );

        $this->assertSame(
            0, $lambdaCalls,
            "Pending decoupled TAN must NOT trigger a fresh login() — that " .
            "would generate a brand-new TAN challenge at the bank and lead " .
            "to rate-limit lockouts (e.g. DKB error 9040)."
        );
    }

    /**
     * Baseline behavior we must NOT break: when there's no saved action in
     * the session at all (fresh wizard start), the lambda IS invoked once
     * to create the initial action.
     */
    public function test_fresh_session_calls_lambda_once(): void
    {
        $session = $this->fakeSession();

        $finTs = $this->createMock(FinTs::class);

        $lambdaCalls = 0;
        $action = $this->createMock(BaseAction::class);
        $lambda = function () use (&$lambdaCalls, $action) {
            $lambdaCalls++;
            return $action;
        };

        new TanHandler(
            $lambda,
            'login-action',
            $session,
            $this->fakeTwig(),
            $finTs,
            $this->fakeStep(),
            $this->fakeRequest(),
        );

        $this->assertSame(1, $lambdaCalls, "Fresh session must call lambda exactly once.");
    }

    /**
     * Non-decoupled TAN handling must continue to work: if the saved action
     * is non-decoupled (the user typed a TAN into the form), TanHandler
     * should submit that TAN to FinTs::submitTan and NOT recreate the action.
     */
    public function test_non_decoupled_submits_tan_and_keeps_action(): void
    {
        $action = $this->createMock(BaseAction::class);

        $session = $this->fakeSession();
        $session->set('login-action', serialize($action));

        $tanMode = $this->createMock(TanMode::class);
        $tanMode->method('isDecoupled')->willReturn(false);

        $request = new Request();
        $request->request->set('tan', '123456');

        $finTs = $this->createMock(FinTs::class);
        $finTs->method('getSelectedTanMode')->willReturn($tanMode);
        $finTs->expects($this->once())
            ->method('submitTan')
            ->with($this->isInstanceOf(BaseAction::class), '123456');

        $lambdaCalls = 0;
        $lambda = function () use (&$lambdaCalls, $action) {
            $lambdaCalls++;
            return $action;
        };

        new TanHandler(
            $lambda,
            'login-action',
            $session,
            $this->fakeTwig(),
            $finTs,
            $this->fakeStep(),
            $request,
        );

        $this->assertSame(0, $lambdaCalls, "Non-decoupled TAN path must not call the action lambda.");
    }
}
