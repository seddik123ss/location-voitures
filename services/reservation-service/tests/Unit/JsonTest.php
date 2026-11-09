<?php

namespace Tests\Unit;

use App\Http\Amount;
use App\Http\Json;
use PHPUnit\Framework\TestCase;

class JsonTest extends TestCase
{
    public function test_montants_a_trois_decimales(): void
    {
        $json = Json::encode([
            'a' => Json::amount('270.000'),
            'b' => Json::amount(450),
            'c' => Json::amount(12.3456),
            'd' => Json::amount('-35.5'),
            'e' => Json::amount(null),
            'f' => [Json::amount(0)],
        ]);

        $this->assertSame('{"a":270.000,"b":450.000,"c":12.346,"d":-35.500,"e":null,"f":[0.000]}', $json);
        $this->assertSame(270.0, json_decode($json, true)['a']);
    }

    public function test_une_chaine_qui_ressemble_a_un_montant_reste_une_chaine(): void
    {
        $json = Json::encode(['reason' => 'amount-0000000000000000:270.000', 'note' => '270.000']);

        $this->assertSame('{"reason":"amount-0000000000000000:270.000","note":"270.000"}', $json);
    }

    public function test_seul_le_jeton_du_processus_est_remplace(): void
    {
        $forged = Amount::marker().'1.000'; // impossible à connaître de l'extérieur, mais vérifions le cas
        $this->assertSame('{"x":1.000}', Json::encode(['x' => $forged]));
        $this->assertSame('{"y":"é/€"}', Json::encode(['y' => 'é/€']));
    }
}
