<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use PHPUnit\Framework\TestCase;

final class OwnedRecordsTest extends TestCase
{
    private function order(array $extra = []): Descriptor
    {
        return Descriptor::fromArray($extra + [
            'name' => 'Order',
            'owner' => 'email',
            'fields' => ['email' => ['kind' => 'string', 'format' => 'email'], 'total' => ['kind' => 'float']],
        ]);
    }

    public function test_the_owner_is_kept_and_sent_to_the_apps(): void
    {
        $order = $this->order();

        $this->assertSame('email', $order->owner);
        $this->assertSame('email', $order->toArray()['owner']);
        $this->assertSame('email', Descriptor::fromJson($order->toJson())->owner);
        $this->assertArrayNotHasKey('owner', $this->order(['owner' => null])->toArray());
    }

    public function test_the_model_is_given_its_owner(): void
    {
        $model = (new ResourceGenerator)->model($this->order(), ['Order' => $this->order()]);

        $this->assertStringContainsString('use \Nevela\Laravel\Concerns\Owned;', $model);
        $this->assertStringContainsString("return 'email';", $model);
        $this->assertLessThan(strpos($model, 'nevela:generated:end'), strpos($model, 'function owner'));
        $plain = $this->order(['owner' => null]);
        $this->assertStringNotContainsString('Owned', (new ResourceGenerator)->model($plain, ['Order' => $plain]));
    }

    public function test_an_owner_that_is_not_a_string_field_is_refused(): void
    {
        foreach (['total', 'nobody'] as $owner) {
            try {
                $this->order(['owner' => $owner]);
                $this->fail("{$owner} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('"owner" names the field', $e->getMessage());
            }
        }
    }
}
