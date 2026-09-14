<?php

declare(strict_types=1);

namespace App\Tests\Functional\Validator;

use App\Entity\Contact;
use App\Entity\Organization;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Integration-тесты серверной валидации контакта (change contacts-crud,
 * задачи 4.3/7.2): обязательные поля name/organization и лимиты длины
 * с сообщениями на русском.
 */
final class ContactValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $this->validator = $validator;
    }

    public function testValidContactHasNoViolations(): void
    {
        $contact = new Contact()
            ->setOrganization(new Organization()->setName('ООО Ромашка')->setIndustry('IT'))
            ->setName('Иван Петров')
            ->setPhone('+7-900-111-11-11')
            ->setEmail('ivan@romashka.ru');

        self::assertSame(0, $this->validator->validate($contact)->count());
    }

    public function testBlankNameShowsRussianMessage(): void
    {
        $contact = new Contact()
            ->setOrganization(new Organization()->setName('ООО Ромашка')->setIndustry('IT'))
            ->setName('');

        $violations = $this->validator->validate($contact);

        self::assertGreaterThanOrEqual(1, $violations->count());
        self::assertSame('name', $violations->get(0)->getPropertyPath());
        self::assertSame('Имя обязательно для заполнения', $violations->get(0)->getMessage());
    }

    public function testMissingOrganizationShowsRussianMessage(): void
    {
        $contact = new Contact()
            ->setName('Иван Петров');

        $violations = $this->validator->validate($contact);

        $messages = [];
        foreach ($violations as $violation) {
            $messages[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        self::assertArrayHasKey('organization', $messages);
        self::assertSame('Организация обязательна для выбора', $messages['organization']);
        self::assertArrayNotHasKey('name', $messages);
    }

    public function testNameLongerThan255CharactersRejected(): void
    {
        $contact = new Contact()
            ->setOrganization(new Organization()->setName('ООО Ромашка')->setIndustry('IT'))
            ->setName(str_repeat('А', 256));

        $violations = $this->validator->validate($contact);

        self::assertSame(1, $violations->count());
        self::assertSame('name', $violations->get(0)->getPropertyPath());
        self::assertSame(
            'Имя не должно превышать 255 символов',
            (string) $violations->get(0)->getMessage()
        );
    }

    public function testPhoneLongerThan32CharactersRejected(): void
    {
        $contact = new Contact()
            ->setOrganization(new Organization()->setName('ООО Ромашка')->setIndustry('IT'))
            ->setName('Иван Петров')
            ->setPhone(str_repeat('1', 33));

        $violations = $this->validator->validate($contact);

        self::assertSame(1, $violations->count());
        self::assertSame('phone', $violations->get(0)->getPropertyPath());
        self::assertSame(
            'Телефон не должен превышать 32 символов',
            (string) $violations->get(0)->getMessage()
        );
    }
}
