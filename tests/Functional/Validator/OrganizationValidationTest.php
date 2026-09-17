<?php

declare(strict_types=1);

namespace App\Tests\Functional\Validator;

use App\Entity\Organization;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Integration-тесты серверной валидации организации (change organizations-crud,
 * задачи 4.3/7.2): обязательные поля и лимиты длины с сообщениями на русском.
 */
final class OrganizationValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $this->validator = $validator;
    }

    public function testValidOrganizationHasNoViolations(): void
    {
        $organization = new Organization()
            ->setName('ООО Ромашка')
            ->setIndustry('IT');

        self::assertSame(0, $this->validator->validate($organization)->count());
    }

    public function testBlankNameShowsRussianMessage(): void
    {
        $organization = new Organization()
            ->setName('')
            ->setIndustry('IT');

        $violations = $this->validator->validate($organization);

        self::assertCount(1, $violations);
        self::assertSame('name', $violations->get(0)->getPropertyPath());
        self::assertSame('Название обязательно для заполнения', $violations->get(0)->getMessage());
    }

    public function testBlankIndustryIsAllowed(): void
    {
        $organization = new Organization()
            ->setName('ООО Ромашка')
            ->setIndustry('');

        $messages = [];
        foreach ($this->validator->validate($organization) as $violation) {
            $messages[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        // industry необязателен — ошибки нет.
        self::assertArrayNotHasKey('industry', $messages);
        self::assertArrayNotHasKey('name', $messages);
    }

    public function testOnlyNameRequiredWhenBothEmpty(): void
    {
        $organization = new Organization()
            ->setName('')
            ->setIndustry('');

        $paths = [];
        foreach ($this->validator->validate($organization) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        self::assertSame(['name'], $paths);
    }

    public function testNameLongerThan255CharactersRejected(): void
    {
        $organization = new Organization()
            ->setName(str_repeat('А', 256))
            ->setIndustry('IT');

        $violations = $this->validator->validate($organization);

        self::assertSame(1, $violations->count());
        self::assertSame('name', $violations->get(0)->getPropertyPath());
        self::assertSame(
            'Название не должно превышать 255 символов',
            (string) $violations->get(0)->getMessage()
        );
    }
}
