<?php
// The password-reset email must link to the real web app, not the local
// dev server. The address comes from FRONTEND_URL, else CORS_ORIGIN, else
// the dev default; a blank setting counts as unset.
class FrontendUrlTest extends ActionTestCase
{
    private $savedEnv;

    protected function setUp(): void
    {
        $this->savedEnv = $_ENV;
        unset($_ENV['FRONTEND_URL'], $_ENV['CORS_ORIGIN']);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->savedEnv;
    }

    // The link inside the reset email an action queued.
    private function resetLink()
    {
        $this->call('AuthApiHarness', 'forgotPassword', array(), array('email' => 'ana@sdca.edu.ph'), array(
            'Auth_Model' => array('findByEmail' => array('id' => 5, 'name' => 'Ana'), 'createPasswordResetToken' => 'tok'),
        ));
        $body = $this->controller->Email_Model->calls[0][1][2];
        preg_match('/href="([^"]+)"/', $body, $m);
        return html_entity_decode($m[1]);
    }

    public function testWithNothingConfiguredItFallsBackToTheLocalDevServer()
    {
        $this->assertSame('http://localhost:5173/reset-password?token=tok', $this->resetLink());
    }

    public function testCorsOriginAloneIsEnoughForARealDeployment()
    {
        $_ENV['CORS_ORIGIN'] = 'https://app.sdca.edu.ph';

        $this->assertSame('https://app.sdca.edu.ph/reset-password?token=tok', $this->resetLink());
    }

    public function testFrontendUrlWinsOverCorsOrigin()
    {
        $_ENV['CORS_ORIGIN'] = 'https://app.sdca.edu.ph';
        $_ENV['FRONTEND_URL'] = 'https://web.sdca.edu.ph';

        $this->assertSame('https://web.sdca.edu.ph/reset-password?token=tok', $this->resetLink());
    }

    public function testABlankFrontendUrlFallsThroughToCorsOrigin()
    {
        $_ENV['FRONTEND_URL'] = '';
        $_ENV['CORS_ORIGIN'] = 'https://app.sdca.edu.ph';

        $this->assertSame('https://app.sdca.edu.ph/reset-password?token=tok', $this->resetLink());
    }

    public function testBothBlankFallsBackToTheLocalDevServer()
    {
        $_ENV['FRONTEND_URL'] = '';
        $_ENV['CORS_ORIGIN'] = '';

        $this->assertSame('http://localhost:5173/reset-password?token=tok', $this->resetLink());
    }

    public function testATrailingSlashDoesNotDoubleUp()
    {
        $_ENV['FRONTEND_URL'] = 'https://web.sdca.edu.ph/';

        $this->assertSame('https://web.sdca.edu.ph/reset-password?token=tok', $this->resetLink());
    }
}
