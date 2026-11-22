<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\OpenApi;
use PHPUnit\Framework\TestCase;

/**
 * The OpenAPI document as the builder writes it from descriptors. Whether it matches what
 * a generated app answers is apps/api's OpenApiTest; this is what each kind of field,
 * route and option becomes.
 */
final class OpenApiTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(Descriptor ...$descriptors): array
    {
        return (new OpenApi($descriptors, 'https://api.example.com/api', 'Shop', '1.2.3', currency: 'UGX'))->document();
    }

    private function venue(): Descriptor
    {
        return Descriptor::fromArray([
            'name' => 'Venue',
            'id' => 'int',
            'fields' => ['name' => ['kind' => 'string', 'unique' => true], 'logo' => ['kind' => 'file', 'accept' => ['image'], 'required' => false]],
            'public' => ['read' => ['by' => 'name', 'fields' => ['name', 'logo']]],
        ]);
    }

    private function booking(): Descriptor
    {
        return Descriptor::fromArray([
            'name' => 'Booking',
            'fields' => [
                'venueId' => ['kind' => 'belongsTo', 'target' => 'Venue'],
                'guest' => ['kind' => 'string', 'maxLength' => 80],
                'email' => ['kind' => 'string', 'format' => 'email', 'required' => false],
                'stars' => ['kind' => 'int', 'format' => 'rating', 'max' => 10, 'required' => false],
                'share' => ['kind' => 'float', 'format' => 'percent', 'required' => false],
                'amount' => ['kind' => 'float', 'format' => 'money'],
                'status' => ['kind' => 'enum', 'options' => ['pending', 'confirmed'], 'optionLabels' => ['pending' => 'Waiting'], 'default' => 'pending'],
                'reason' => ['kind' => 'string', 'when' => ['status' => 'confirmed']],
                'paid' => ['kind' => 'boolean'],
                'day' => ['kind' => 'date'],
                'notes' => ['kind' => 'text', 'format' => 'rich', 'required' => false],
                'extras' => ['kind' => 'json', 'required' => false],
                'scans' => ['kind' => 'file', 'accept' => ['pdf'], 'multiple' => true, 'required' => false],
                'contract' => ['kind' => 'file', 'accept' => ['pdf'], 'private' => true, 'required' => false],
            ],
            'actions' => [
                'confirm' => ['on' => 'record', 'when' => ['status' => 'pending'], 'many' => true, 'confirm' => 'Confirm it?', 'fields' => ['note' => ['kind' => 'string', 'required' => false]]],
                'export' => ['on' => 'collection'],
            ],
        ]);
    }

    public function test_it_says_where_the_api_is_and_how_to_sign_in(): void
    {
        $doc = $this->document($this->venue());

        $this->assertSame('3.1.0', $doc['openapi']);
        $this->assertSame(['title' => 'Shop', 'version' => '1.2.3'], array_intersect_key($doc['info'], ['title' => 1, 'version' => 1]));
        $this->assertSame([['url' => 'https://api.example.com/api']], $doc['servers']);
        $this->assertSame('bearer', $doc['components']['securitySchemes']['bearer']['scheme']);
        $this->assertSame([], $doc['paths']['/auth/token']['post']['security']);
        $without = (new OpenApi([$this->venue()], 'https://x.test/api', auth: false))->document();
        $this->assertArrayNotHasKey('/auth/token', $without['paths']);
    }

    public function test_each_resource_has_its_routes_with_their_permissions(): void
    {
        $paths = $this->document($this->venue(), $this->booking())['paths'];

        $this->assertSame(['get', 'post'], array_keys($paths['/bookings']));
        $this->assertSame(['parameters', 'get', 'put', 'patch', 'delete'], array_keys($paths['/bookings/{id}']));
        foreach (['/bookings/_stats', '/bookings/_insights', '/bookings/_bulk'] as $path) {
            $this->assertArrayHasKey($path, $paths);
        }
        $this->assertSame('bookings.view', $paths['/bookings']['get']['x-nevela-permission']);
        $this->assertSame('bookings.edit', $paths['/bookings/{id}']['patch']['x-nevela-permission']);
        $this->assertSame('bookings.delete', $paths['/bookings/{id}']['delete']['x-nevela-permission']);
        // An upload to each file field, as the body and as a form.
        $this->assertSame(['put', 'post'], array_keys($paths['/_nevela/uploads/Booking/scans']));
        $this->assertArrayHasKey('multipart/form-data', $paths['/_nevela/uploads/Booking/scans']['post']['requestBody']['content']);
        // An integer id in the address is digits.
        $this->assertSame('^[0-9]{1,18}$', $paths['/venues/{id}']['parameters'][0]['schema']['pattern']);
    }

    public function test_actions_are_on_a_record_on_several_and_on_the_list(): void
    {
        $paths = $this->document($this->venue(), $this->booking())['paths'];

        $one = $paths['/bookings/{id}/_actions/confirm']['post'];
        $this->assertSame('bookings.confirm', $one['x-nevela-permission']);
        $this->assertSame('Confirm it?', $one['x-nevela-confirm']);
        $this->assertStringContainsString('status is "pending"', $one['description']);
        $this->assertArrayHasKey('409', $one['responses']);
        $several = $paths['/bookings/_actions/confirm']['post'];
        $this->assertSame(['ids'], $several['requestBody']['content']['application/json']['schema']['required']);
        $this->assertArrayHasKey('done', $several['responses']['200']['content']['application/json']['schema']['properties']);
        $this->assertArrayHasKey('/bookings/_actions/export', $paths);
        $this->assertArrayNotHasKey('/bookings/{id}/_actions/export', $paths);
    }

    public function test_a_record_lists_every_key_it_has_and_no_other(): void
    {
        $schema = $this->document($this->venue(), $this->booking())['components']['schemas']['Booking'];

        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(array_keys($schema['properties']), $schema['required']);
        $this->assertSame(
            ['id', 'venueId', 'guest', 'email', 'stars', 'share', 'amount', 'status', 'reason', 'paid', 'day', 'notes', 'extras', 'scans', 'scansFiles', 'contract', 'contractFile', 'createdAt', 'updatedAt'],
            array_keys($schema['properties']),
        );
        $p = $schema['properties'];
        $this->assertSame(['type' => 'string', 'format' => 'uuid'], $p['id']);
        // An integer id is a string in what the API answers.
        $this->assertSame('string', $p['venueId']['type']);
        $this->assertSame(['string', 'null'], $p['email']['type']);
        $this->assertSame([1, 10], [$p['stars']['minimum'], $p['stars']['maximum']]);
        $this->assertSame([0, 100], [$p['share']['minimum'], $p['share']['maximum']]);
        $this->assertSame('An amount of money in UGX.', $p['amount']['description']);
        $this->assertSame(['pending', 'confirmed'], $p['status']['enum']);
        $this->assertSame(['Waiting', 'Confirmed'], $p['status']['x-enum-descriptions']);
        // Only for some statuses, so sometimes empty.
        $this->assertSame(['string', 'null'], $p['reason']['type']);
        $this->assertSame('text/html', $p['notes']['contentMediaType']);
        $this->assertSame(['object', 'array', 'null'], $p['extras']['type']);
        $this->assertSame('#/components/schemas/NevelaPrivateFile', $p['contractFile']['oneOf'][0]['$ref']);
        $this->assertSame('#/components/schemas/NevelaFile', $p['scansFiles']['items']['oneOf'][0]['$ref']);
    }

    public function test_a_create_requires_what_the_generated_request_does(): void
    {
        $schemas = $this->document($this->venue(), $this->booking())['components']['schemas'];

        // Not status (it has a default), reason (only sometimes), paid (left alone, a no) or anything optional.
        $this->assertSame(['venueId', 'guest', 'amount', 'day'], $schemas['BookingInput']['required']);
        $this->assertSame(['venueId', 'guest', 'amount', 'day'], $schemas['BookingReplace']['required']);
        $this->assertArrayNotHasKey('required', $schemas['BookingPatch']);
        $input = $schemas['BookingInput']['properties'];
        $this->assertSame('pending', $input['status']['default']);
        $this->assertSame(80, $input['guest']['maxLength']);
        // Coming in, an integer id may be a number.
        $this->assertSame(['string', 'integer'], $input['venueId']['type']);
        $this->assertSame([['array', 'null'], ['type' => 'string'], 20], [$input['scans']['type'], $input['scans']['items'], $input['scans']['maxItems']]);
        $this->assertArrayNotHasKey('updatedAt', $input);
        $this->assertArrayHasKey('updatedAt', $schemas['BookingPatch']['properties']);
        $this->assertSame(['type' => 'object', 'properties' => ['note' => ['type' => ['string', 'null'], 'maxLength' => 255, 'title' => 'Note']]], json_decode(json_encode($schemas['BookingConfirmInput']), true));
    }

    public function test_a_list_can_be_sorted_and_filtered_by_what_the_api_allows(): void
    {
        $parameters = $this->document($this->venue(), $this->booking())['paths']['/bookings']['get']['parameters'];
        $byName = array_column($parameters, null, 'name');

        $this->assertContains('-amount', $byName['sort']['schema']['enum']);
        $this->assertNotContains('notes', $byName['sort']['schema']['enum']);
        $this->assertSame(['pending', 'confirmed'], $byName['filter[status]']['schema']['enum']);
        $this->assertSame('boolean', $byName['filter[paid]']['schema']['type']);
        $this->assertArrayNotHasKey('filter[notes]', $byName);
        $this->assertArrayNotHasKey('filter[extras]', $byName);
        $this->assertStringContainsString('guest', $byName['q']['description']);
        // The operators each kind takes, and only those.
        $this->assertSame(['type' => 'number'], $byName['filter[amount][gt]']['schema']);
        $this->assertArrayHasKey('filter[amount][between]', $byName);
        $this->assertArrayHasKey('filter[day][lt]', $byName);
        $this->assertArrayHasKey('filter[guest][contains]', $byName);
        $this->assertArrayHasKey('filter[status][in]', $byName);
        $this->assertArrayHasKey('filter[createdAt][gt]', $byName);
        $this->assertArrayNotHasKey('filter[status][gt]', $byName);
        $this->assertArrayNotHasKey('filter[paid][in]', $byName);
        $this->assertArrayNotHasKey('include', $byName);
    }

    public function test_the_public_side_is_only_what_it_opens_and_needs_no_token(): void
    {
        $doc = $this->document($this->venue());

        $list = $doc['paths']['/public/venues']['get'];
        $this->assertSame([], $list['security']);
        $this->assertSame(['id', 'name', 'logo', 'logoFile'], array_keys($doc['components']['schemas']['PublicVenue']['properties']));
        $this->assertSame('Its name.', $doc['paths']['/public/venues/{id}']['get']['parameters'][0]['description']);
        $this->assertArrayNotHasKey('post', $doc['paths']['/public/venues']);
    }

    public function test_names_an_app_chooses_meet_nothing_of_nevela_s_own(): void
    {
        // A resource called File, an action called show, and PublicVenue beside Venue's public side.
        $file = Descriptor::fromArray(['name' => 'File', 'fields' => ['title' => ['kind' => 'string'], 'scan' => ['kind' => 'file', 'accept' => ['pdf'], 'required' => false]], 'actions' => ['show' => ['on' => 'record']]]);
        $publicVenue = Descriptor::fromArray(['name' => 'PublicVenue', 'fields' => ['name' => ['kind' => 'string']]]);
        $doc = $this->document($file, $this->venue(), $publicVenue);

        $this->assertSame(['title', 'scan'], array_slice(array_keys($doc['components']['schemas']['File']['properties']), 1, 2));
        $this->assertSame('#/components/schemas/NevelaFile', $doc['components']['schemas']['File']['properties']['scanFile']['oneOf'][0]['$ref']);
        $this->assertArrayHasKey('renditions', $doc['components']['schemas']['NevelaFile']['properties']);
        $ids = [];
        foreach ($doc['paths'] as $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (isset($item[$method])) {
                    $ids[] = $item[$method]['operationId'];
                }
            }
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'an operationId is used twice');
        $this->assertContains('runFileShow', $ids);
        $this->assertContains('showFile', $ids);
    }

    public function test_a_resource_with_lines_takes_them_inline(): void
    {
        $order = Descriptor::fromArray(['name' => 'Order', 'items' => 'OrderLine', 'fields' => ['customer' => ['kind' => 'string']]]);
        $line = Descriptor::fromArray(['name' => 'OrderLine', 'fields' => ['orderId' => ['kind' => 'belongsTo', 'target' => 'Order'], 'quantity' => ['kind' => 'int']]]);
        $schemas = $this->document($order, $line)['components']['schemas'];

        $this->assertSame('#/components/schemas/OrderLineLine', $schemas['OrderInput']['properties']['items']['items']['$ref']);
        $this->assertSame(['id', 'quantity'], array_keys($schemas['OrderLineLine']['properties']));
        $this->assertSame(['quantity'], $schemas['OrderLineLine']['required']);
        // A record's own page has them; a list doesn't, so they aren't required.
        $this->assertArrayHasKey('items', $schemas['Order']['properties']);
        $this->assertNotContains('items', $schemas['Order']['required']);
        $include = array_column($this->document($order, $line)['paths']['/orders']['get']['parameters'], null, 'name')['include'];
        $this->assertSame(['items'], $include['schema']['enum']);
    }
}
