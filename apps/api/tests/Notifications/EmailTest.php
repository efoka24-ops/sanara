<?php
declare(strict_types=1);

namespace Sanara\Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Sanara\Modules\Notifications\Email\EmailTemplates;

final class EmailTest extends TestCase
{
    public function testRendersFrenchAndEnglishWithVariables(): void
    {
        $t = new EmailTemplates();
        $fr = $t->render('access_granted', 'fr', ['responsable' => 'Dr Mballa', 'pharmacie' => 'Pharmacie Saint-Luc', 'duree_lien' => '48 heures', 'lien_activation' => 'https://x/activer/1']);
        $this->assertSame('Votre accès Sanara est prêt', $fr['subject']);
        $this->assertStringContainsString('Dr Mballa', $fr['html']);
        $this->assertStringContainsString('48 heures', $fr['text']);
        $en = $t->render('access_granted', 'en', ['responsable' => 'Dr Mballa', 'pharmacie' => 'P', 'duree_lien' => '48 hours', 'lien_activation' => 'https://x']);
        $this->assertSame('Your Sanara access is ready', $en['subject']);
    }

    public function testEscapesHtmlInVariables(): void
    {
        $r = (new EmailTemplates())->render('access_granted', 'fr', ['responsable' => '<script>x</script>', 'pharmacie' => 'P', 'duree_lien' => '1', 'lien_activation' => 'https://x']);
        $this->assertStringNotContainsString('<script>', $r['html']);
    }

    public function testMissingVariableOrTemplateFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EmailTemplates())->render('access_granted', 'fr', ['responsable' => 'x']);
    }
}
