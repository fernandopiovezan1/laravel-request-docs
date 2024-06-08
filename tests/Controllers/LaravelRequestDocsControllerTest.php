<?php

namespace Rakutentech\LaravelRequestDocs\Tests\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\API\Group1Controller;
use Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\API\Group2Controller;
use Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\PathController;
use Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\UserController;
use Rakutentech\LaravelRequestDocs\Tests\TestCase;

class LaravelRequestDocsControllerTest extends TestCase
{
    public function testApi()
    {
        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $expected = (array) json_decode(
            File::get(base_path('tests/mocks/lrd-response.json')),
            true,
        );

        /** {@see \Rakutentech\LaravelRequestDocs\Tests\TestCase::registerRoutes()} */
        $this->assertCount(29, $response->json());

        $this->assertSame($expected, $response->json());
    }

    public function testApiCanHideMetadata()
    {
        Config::set('request-docs.hide_meta_data', true);

        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $docs = collect($response->json());

        $this->assertEmpty($docs->pluck('middlewares')->flatten()->toArray());
        $this->assertSame([''], $docs->pluck('controller')->flatten()->unique()->toArray());
        $this->assertSame([''], $docs->pluck('controller_full_path')->flatten()->unique()->toArray());
        $this->assertSame([''], $docs->pluck('method')->flatten()->unique()->toArray());
        $this->assertSame([''], $docs->pluck('doc_block')->flatten()->unique()->toArray());
        $this->assertEmpty($docs->pluck('rules')->flatten()->toArray());
    }

    public function testAbleFetchAllMethods()
    {
        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $docs = collect($response->json());

        $this->assertSame(
            [
                Request::METHOD_DELETE,
                Request::METHOD_GET,
                Request::METHOD_HEAD,
                Request::METHOD_PATCH,
                Request::METHOD_POST,
                Request::METHOD_PUT,
            ],
            $docs->pluck('http_method')
                ->flatten()
                ->unique()
                ->sort()
                ->values()
                ->toArray()
        );
    }

    public function testAbleFilterMethod()
    {
        $methodMap = [
            'showDelete' => Request::METHOD_DELETE,
            'showGet'    => Request::METHOD_GET,
            'showHead'   => Request::METHOD_HEAD,
            'showPatch'  => Request::METHOD_PATCH,
            'showPost'   => Request::METHOD_POST,
            'showPut'    => Request::METHOD_PUT,
        ];

        foreach ($methodMap as $request => $method) {
            $response = $this->get(route('request-docs.api') . '?' . $request . '=false')
                ->assertStatus(Response::HTTP_OK);

            $docs = collect($response->json());

            $expected = array_filter([
                Request::METHOD_DELETE,
                Request::METHOD_GET,
                Request::METHOD_HEAD,
                Request::METHOD_PATCH,
                Request::METHOD_POST,
                Request::METHOD_PUT,
            ], fn($expectedMethod) => $expectedMethod !== $method);

            $expected = array_values($expected);

            $this->assertSame(
                $expected,
                $docs->pluck('http_method')
                    ->flatten()
                    ->unique()
                    ->sort()
                    ->values()
                    ->toArray()
            );
        }
    }

    public function testOnlyRouteURIStartWith()
    {
        Config::set('request-docs.only_route_uri_start_with', 'welcome');

        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $docs = collect($response->json());

        foreach ($docs as $doc) {
            $this->assertStringStartsWith('welcome', $doc['uri']);
        }
    }

    public function testSortDocsByRouteNames()
    {
        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $docs = collect($response->json());

        // Sort manually.
        $expected = $docs->pluck('uri')->unique()->sort()->values()->toArray();

        $response = $this->get(route('request-docs.api') . '?sort=route_names')
            ->assertStatus(Response::HTTP_OK);

        $docs = collect($response->json());

        $sorted = $docs->pluck('uri')->unique()->values()->toArray();

        $this->assertSame($expected, $sorted);
    }

    public function testSortDocsByMethodNames()
    {
        $response = $this->get(route('request-docs.api') . '?sort=method_names')
            ->assertStatus(Response::HTTP_OK);

        $docs   = collect($response->json());
        $sorted = $docs->pluck('http_method')->unique()->values()->toArray();

        $this->assertSame(
            [
                Request::METHOD_GET,
                Request::METHOD_POST,
                Request::METHOD_PUT,
                Request::METHOD_PATCH,
                Request::METHOD_DELETE,
                Request::METHOD_HEAD,
            ],
            $sorted
        );
    }

    public function testGroupByAPIURI()
    {
        Route::get('users', UserController::class);
        Route::post('users', UserController::class);
        Route::put('users/update', UserController::class);
        Route::put('api/users/', UserController::class);
        Route::put('api/users/{id}', UserController::class);
        Route::put('api/users_roles/{id}', UserController::class);
        Route::put('api/v1/users', UserController::class);
        Route::put('api/v1/users/{id}/store', UserController::class);
        Route::put('api/v2/users', UserController::class);
        Route::put('api/v99/users', UserController::class);

        $response = $this->get(route('request-docs.api') . '?groupby=api_uri')
            ->assertStatus(Response::HTTP_OK);

        $docs = collect($response->json());

        $expected = [
            'api/users'       => [
                [
                    'uri'         => 'api/users',
                    'group'       => 'api/users',
                    'group_index' => 0
                ],
                [
                    'uri'         => 'api/users/{id}',
                    'group'       => 'api/users',
                    'group_index' => 1
                ]
            ],
            'api/users_roles' => [
                [
                    'uri'         => 'api/users_roles/{id}',
                    'group'       => 'api/users_roles',
                    'group_index' => 0
                ]
            ],
            'api/v1/users'    => [
                [
                    'uri'         => 'api/v1/users',
                    'group'       => 'api/v1/users',
                    'group_index' => 0
                ],
                [
                    'uri'         => 'api/v1/users/{id}/store',
                    'group'       => 'api/v1/users',
                    'group_index' => 1
                ]
            ],
            'api/v2/users'    => [
                [
                    'uri'         => 'api/v2/users',
                    'group'       => 'api/v2/users',
                    'group_index' => 0
                ]
            ],
            'api/v99/users'   => [
                [
                    'uri'         => 'api/v99/users',
                    'group'       => 'api/v99/users',
                    'group_index' => 0
                ]
            ],
            'users'           => [
                [
                    'uri'         => 'users',
                    'group'       => 'users',
                    'group_index' => 0
                ],
                [
                    'uri'         => 'users',
                    'group'       => 'users',
                    'group_index' => 1
                ],
                [
                    'uri'         => 'users',
                    'group'       => 'users',
                    'group_index' => 2
                ],
                [
                    'uri'         => 'users/update',
                    'group'       => 'users',
                    'group_index' => 3
                ]
            ]
        ];

        $grouped = $docs
            ->filter(fn(array $item) => Str::startsWith($item['uri'], ['users', 'api']))
            ->map(fn(array $item) => collect($item)->only(['uri', 'group', 'group_index'])->toArray())
            ->groupBy('group')
            ->toArray();

        $this->assertSame($expected, $grouped);
    }

    public function testGroupDocsIsSortedByGroupAndGroupIndex()
    {
        // Define routes with random ordering.
        Route::post('api/v1/users/store', UserController::class);
        Route::get('api/v1/users', UserController::class);

        Route::post('api/v1/health', UserController::class);

        Route::put('api/v1/users/update', UserController::class);
        Route::delete('api/v1/users/destroy', UserController::class);

        Route::get('api/v1/health', UserController::class);

        $response = $this->get(route('request-docs.api') . '?groupby=api_uri')
            ->assertStatus(Response::HTTP_OK);

        $docs = collect($response->json());

        $grouped = $docs
            ->filter(fn(array $item) => Str::startsWith($item['uri'], ['api']))
            ->map(fn(array $item) => collect($item)->only(['group', 'group_index'])->toArray())
            ->values()
            ->toArray();

        $expected = [
            [
                'group'       => 'api/v1/health',
                'group_index' => 0
            ],
            [
                'group'       => 'api/v1/health',
                'group_index' => 1
            ],
            [
                'group'       => 'api/v1/health',
                'group_index' => 2
            ],
            [
                'group'       => 'api/v1/users',
                'group_index' => 0
            ],
            [
                'group'       => 'api/v1/users',
                'group_index' => 1
            ],
            [
                'group'       => 'api/v1/users',
                'group_index' => 2
            ],
            [
                'group'       => 'api/v1/users',
                'group_index' => 3
            ],
            [
                'group'       => 'api/v1/users',
                'group_index' => 4
            ]
        ];
    }

    public function testGroupByURIBackwardCompatible()
    {
        // Set to `null` to test backward compatibility.
        Config::set('request-docs.group_by.uri_patterns', []);

        $this->get(route('request-docs.api') . '?groupby=api_uri')
            ->assertStatus(Response::HTTP_OK);
    }

    public function testGroupByControllerFullPath()
    {
        Route::post('api/group1', [Group1Controller::class, 'store']);
        Route::put('api/group1', [Group1Controller::class, 'update']);
        Route::get('api/group2', [Group2Controller::class, 'show']);
        Route::delete('api/group2', [Group2Controller::class, 'destroy']);

        $response = $this->get(route('request-docs.api') . '?groupby=controller_full_path')
            ->assertStatus(Response::HTTP_OK);

        $docs = collect($response->json());

        $expected = [
            'Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\API\Group1Controller' => [
                [
                    'method'      => 'store',
                    'http_method' => 'POST',
                    'group'       => 'Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\API\Group1Controller',
                    'group_index' => 0
                ],
                [
                    'method'      => 'update',
                    'http_method' => 'PUT',
                    'group'       => 'Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\API\Group1Controller',
                    'group_index' => 1
                ],
            ],
            'Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\API\Group2Controller' => [
                [
                    'method'      => 'show',
                    'http_method' => 'GET',
                    'group'       => 'Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\API\Group2Controller',
                    'group_index' => 0
                ],
                [
                    'method'      => 'show',
                    'http_method' => 'HEAD',
                    'group'       => 'Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\API\Group2Controller',
                    'group_index' => 1
                ],
                [
                    'method'      => 'destroy',
                    'http_method' => 'DELETE',
                    'group'       => 'Rakutentech\LaravelRequestDocs\Tests\Stubs\TestControllers\API\Group2Controller',
                    'group_index' => 2
                ]
            ]
        ];

        $grouped = $docs
            ->filter(fn(array $item) => Str::startsWith($item['uri'], ['api']))
            ->map(fn(array $item) => collect($item)->only(['method', 'http_method', 'group', 'group_index'])->toArray())
            ->groupBy('group')
            ->toArray();

        $this->assertSame($expected, $grouped);
    }

    public function testOpenApi()
    {
        $this->get(route('request-docs.api') . '?openapi=true')
            ->assertStatus(Response::HTTP_OK);
    }

    public function testPath()
    {
        Route::get('user/{id}', [PathController::class, 'index'])
            ->where('id', '[0-9]+');

        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $expected = [
            'id' => ['integer|required|regex:/[0-9]+/'],
        ];

        $docs = collect($response->json());

        $pathParameter = $docs->filter(fn(array $doc) => Str::startsWith($doc['uri'], 'user') && $doc['http_method'] === 'GET')
            ->pluck('path_parameters')
            ->first();

        $this->assertSame($expected, $pathParameter);
    }

    public function testPathWithOptional()
    {
        Route::get('user/{name?}', [PathController::class, 'optional'])
            ->where('name', '[A-Za-z]+');

        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $expected = [
            'name' => ['string|nullable|regex:/[A-Za-z]+/'],
        ];

        $docs = collect($response->json());

        $pathParameter = $docs->filter(fn(array $doc) => Str::startsWith($doc['uri'], 'user') && $doc['http_method'] === 'GET')
            ->pluck('path_parameters')
            ->first();

        $this->assertSame($expected, $pathParameter);
    }

    public function testPathWithModelBinding()
    {
        Route::get('user/{user}/{post}/{comment:name}', [PathController::class, 'model']);

        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $expected = [
            'user'         => ['integer|required'],
            'post'         => ['string|required'],
            'comment:name' => ['string|required'],
        ];

        $docs = collect($response->json());

        $pathParameter = $docs->filter(fn(array $doc) => Str::startsWith($doc['uri'], 'user') && $doc['http_method'] === 'GET')
            ->pluck('path_parameters')
            ->first();

        $this->assertSame($expected, $pathParameter);
    }

    public function testPathWithMethodParametersIsLesser()
    {
        Route::get('user/{id}/{user}/{valid?}', [PathController::class, 'index'])
            ->where('missing', '[A-Za-z]+')
            ->where('valid', '[A-Za-z]+');

        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $expected = [
            'id'    => ['integer|required'],
            'user'  => ['string|required'],
            'valid' => ['string|nullable|regex:/[A-Za-z]+/'],
        ];

        $docs = collect($response->json());

        $pathParameter = $docs->filter(fn(array $doc) => Str::startsWith($doc['uri'], 'user') && $doc['http_method'] === 'GET')
            ->pluck('path_parameters')
            ->first();

        $this->assertSame($expected, $pathParameter);
    }

    public function testPathWithGlobalPattern()
    {
        Route::pattern('id', '[0-9]+');

        Route::get('/user/{id}', function (string $id) {
            // Only executed if {id} is numeric...
        });

        $response = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);

        $expected = [
            'id' => ['string|required|regex:/[0-9]+/'],
        ];

        $docs = collect($response->json());

        $pathParameter = $docs->filter(fn(array $doc) => Str::startsWith($doc['uri'], 'user') && $doc['http_method'] === 'GET')
            ->pluck('path_parameters')
            ->first();

        $this->assertSame($expected, $pathParameter);
    }


    public function testGenerateExampleToFactory()
    {
        config(['request-docs.factory_path' => 'Rakutentech\LaravelRequestDocs\Tests\Factories']);
        config(['request-docs.use_factory' => true]);
        config(['request-docs.pattern_model_from_controller_name' => '/Controller$/']);
        Route::post('api/v1/users/store', [UserController::class, 'store']);

        $response  = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);
        $docs      = collect($response->json());
        $userRoute = $docs
            ->filter(fn(array $item) => Str::startsWith($item['uri'], ['api']));
        $examples  = $userRoute->pluck('examples')->toArray();
        $expected  = [\Rakutentech\LaravelRequestDocs\Tests\Factories\UserFactory::new()->make()->toArray()];

        $this->assertSame($expected, $examples);
    }

    public function testGenerateFieldDescriptionAndExamples()
    {
        config(['request-docs.factory_path' => 'Rakutentech\LaravelRequestDocs\Tests\Factories']);
        config(['request-docs.use_factory' => true]);
        config(['request-docs.pattern_model_from_controller_name' => '/Controller$/']);
        Route::post('api/v1/users/store', [UserController::class, 'store']);

        $response  = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);
        $docs      = collect($response->json());
        $userRoute = $docs
            ->filter(fn(array $item) => Str::startsWith($item['uri'], ['api']));
        $fieldInfo = $userRoute->pluck('field_info')->get(0);
        $expected  = [
            'name'  => [
                'description' => 'User Name',
                'example'     => 'John Doe'
            ],
            'email' => [
                'description' => 'User email',
                'example'     => 'johndoe@email.com'
            ]
        ];

        $this->assertSame($expected, $fieldInfo);
    }


    public function testSummaryAndDescriptionMethod()
    {
        Route::post('api/v1/users/store', [UserController::class, 'store']);

        $response    = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);
        $docs        = collect($response->json());
        $userRoute   = $docs
            ->filter(fn(array $item) => Str::startsWith($item['uri'], ['api']));
        $summary     = $userRoute->pluck('summary')->get(0);
        $description = $userRoute->pluck('description')->get(0);

        $expectedSummary     = 'Store a newly created resource in storage.';
        $expectedDescription = 'This method creates a user when validations are met.';

        $this->assertSame($expectedSummary, $summary);
        $this->assertSame($description, $expectedDescription);
    }

    public function testRuleOrder()
    {
        Route::post('api/v1/users/store', [UserController::class, 'store']);

        $response   = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);
        $docs       = collect($response->json());
        $userRoute  = $docs
            ->filter(fn(array $item) => Str::startsWith($item['uri'], ['api']));
        $rulesOrder = $userRoute->pluck('rules_order')->get(0);

        $this->assertSame(config('request-docs.rules_order'), $rulesOrder);
    }

    public function testTag()
    {
        Route::post('api/v1/users/store', [UserController::class, 'store']);

        $response  = $this->get(route('request-docs.api'))
            ->assertStatus(Response::HTTP_OK);
        $docs      = collect($response->json());
        $userRoute = $docs
            ->filter(fn(array $item) => Str::startsWith($item['uri'], ['api']));
        $tag       = $userRoute->pluck('tag')->get(0);

        $this->assertSame('User', $tag);
    }
}
