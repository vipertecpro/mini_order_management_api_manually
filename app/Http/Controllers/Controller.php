<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: 'A small backend where users register, manage products and place orders. '
        .'Log in first, then paste the returned token into the Authorize box above.',
    title: 'Mini Order Management API',
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'API server')]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    description: 'Sanctum personal access token returned by /api/register or /api/login.',
    scheme: 'bearer',
)]
#[OA\Tag(name: 'Auth', description: 'Register, log in and log out')]
#[OA\Tag(name: 'Products', description: 'The catalogue')]
#[OA\Tag(name: 'Orders', description: 'Placing and reading orders')]
#[OA\Response(
    response: 'Unauthenticated',
    description: 'Missing or invalid token',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
        ]
    )
)]
#[OA\Response(
    response: 'Forbidden',
    description: 'The resource belongs to somebody else',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'This action is unauthorized.'),
        ]
    )
)]
#[OA\Response(
    response: 'NotFound',
    description: 'Resource does not exist',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Record not found.'),
        ]
    )
)]
#[OA\Response(
    response: 'ValidationError',
    description: 'Validation failed',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'The name field is required.'),
            new OA\Property(property: 'errors', type: 'object', example: ['name' => ['The name field is required.']]),
        ]
    )
)]
#[OA\Response(
    response: 'TooManyRequests',
    description: 'Rate limit exceeded',
    content: new OA\JsonContent(
        properties: [
            new OA\Property(property: 'message', type: 'string', example: 'Too Many Attempts.'),
        ]
    )
)]
abstract class Controller
{
    //
}
