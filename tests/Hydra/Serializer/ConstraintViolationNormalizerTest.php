<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Tests\Hydra\Serializer;

use ApiPlatform\Api\UrlGeneratorInterface;
use ApiPlatform\Hydra\Serializer\ConstraintViolationListNormalizer;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Serializer\NameConverter\AdvancedNameConverterInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class ConstraintViolationNormalizerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @group legacy
     */
    public function testSupportNormalization(): void
    {
        $urlGeneratorProphecy = $this->prophesize(UrlGeneratorInterface::class);
        $nameConverterProphecy = $this->prophesize(NameConverterInterface::class);

        $normalizer = new ConstraintViolationListNormalizer($urlGeneratorProphecy->reveal(), [], $nameConverterProphecy->reveal());

        $this->assertTrue($normalizer->supportsNormalization(new ConstraintViolationList(), ConstraintViolationListNormalizer::FORMAT));
        $this->assertFalse($normalizer->supportsNormalization(new ConstraintViolationList(), 'xml'));
        $this->assertFalse($normalizer->supportsNormalization(new \stdClass(), ConstraintViolationListNormalizer::FORMAT));
        $this->assertEmpty($normalizer->getSupportedTypes('json'));
        $this->assertSame([ConstraintViolationListInterface::class => true], $normalizer->getSupportedTypes($normalizer::FORMAT));

        if (!method_exists(Serializer::class, 'getSupportedTypes')) {
            $this->assertTrue($normalizer->hasCacheableSupportsMethod());
        }
    }

    /**
     * @dataProvider nameConverterAndPayloadFieldsProvider
     */
    public function testNormalize(callable $nameConverterFactory, ?array $fields, array $expected): void
    {
        $normalizer = new ConstraintViolationListNormalizer(null, $fields, $nameConverterFactory($this));

        // Note : we use NotNull constraint and not Constraint class because Constraint is abstract
        $constraint = new NotNull();
        $constraint->payload = ['severity' => 'warning', 'anotherField2' => 'aValue'];
        $list = new ConstraintViolationList([
            new ConstraintViolation('a', 'b', [], 'c', 'd', 'e', null, 'f24bdbad0becef97a6887238aa58221c', $constraint),
            new ConstraintViolation('1', '2', [], '3', '4', '5'),
        ]);

        $this->assertSame($expected, $normalizer->normalize($list));
    }

    public static function nameConverterAndPayloadFieldsProvider(): iterable
    {
        $basicExpectation = [
            [
                'propertyPath' => 'd',
                'message' => 'a',
                'code' => 'f24bdbad0becef97a6887238aa58221c',
            ],
            [
                'propertyPath' => '4',
                'message' => '1',
                'code' => null,
            ],
        ];

        $nameConverterBasedExpectation = [
            [
                'propertyPath' => '_d',
                'message' => 'a',
                'code' => 'f24bdbad0becef97a6887238aa58221c',
            ],
            [
                'propertyPath' => '_4',
                'message' => '1',
                'code' => null,
            ],
        ];

        $advancedNameConverterFactory = function (self $that) {
            $advancedNameConverterProphecy = $that->prophesize(AdvancedNameConverterInterface::class);
            $advancedNameConverterProphecy->normalize(Argument::type('string'), null, Argument::type('string'))->will(fn ($args): string => '_'.$args[0]);

            return $advancedNameConverterProphecy->reveal();
        };

        $nameConverterFactory = function (self $that) {
            $nameConverterProphecy = $that->prophesize(NameConverterInterface::class);
            $nameConverterProphecy->normalize(Argument::type('string'))->will(fn ($args): string => '_'.$args[0]);

            return $nameConverterProphecy->reveal();
        };

        $nullNameConverterFactory = fn () => null;

        $expected = $nameConverterBasedExpectation;
        $expected[0]['payload'] = ['severity' => 'warning'];
        yield [$advancedNameConverterFactory, ['severity', 'anotherField1'], $expected];
        yield [$nameConverterFactory, ['severity', 'anotherField1'], $expected];
        $expected = $basicExpectation;
        $expected[0]['payload'] = ['severity' => 'warning'];
        yield [$nullNameConverterFactory, ['severity', 'anotherField1'], $expected];

        $expected = $nameConverterBasedExpectation;
        $expected[0]['payload'] = ['severity' => 'warning', 'anotherField2' => 'aValue'];
        yield [$advancedNameConverterFactory, null, $expected];
        yield [$nameConverterFactory, null, $expected];
        $expected = $basicExpectation;
        $expected[0]['payload'] = ['severity' => 'warning', 'anotherField2' => 'aValue'];
        yield [$nullNameConverterFactory, null, $expected];

        yield [$advancedNameConverterFactory, [], $nameConverterBasedExpectation];
        yield [$nameConverterFactory, [], $nameConverterBasedExpectation];
        yield [$nullNameConverterFactory, [], $basicExpectation];
    }
}
