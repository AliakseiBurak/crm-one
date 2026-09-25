<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Call;
use App\Entity\Campaign;
use App\Entity\CampaignAttachment;
use App\Entity\CampaignRecipient;
use App\Entity\Contact;
use App\Entity\Enum\CampaignStatus;
use App\Entity\Enum\UserRole;
use App\Entity\GroupAssignment;
use App\Entity\Organization;
use App\Entity\OrganizationGroup;
use App\Entity\OrganizationHide;
use App\Entity\OrgGroupMembership;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public const ADMIN_EMAIL = 'admin@b2b-crm.loc';
    public const ADMIN_PASSWORD = 'admin123';
    public const MANAGER_EMAIL = 'manager@b2b-crm.loc';
    public const MANAGER_PASSWORD = 'manager123';

    private const SECOND_MANAGER_EMAIL = 'manager2@b2b-crm.loc';
    private const SECOND_MANAGER_PASSWORD = 'manager123';

    private const ORGANIZATIONS = [
        ['ООО "Ромашка"', 'Ритейл'],
        ['АО "Вектор"', 'Логистика'],
        ['ИП Сидоров', 'Услуги'],
        ['ООО "Конкурент"', 'Производство'],
        ['ООО "Горизонт"', 'Строительство'], // без контактов и без звонков
        ['ООО "Закат"', 'Туризм'], // с контактом, без звонков
        ['ООО "Парус"', 'Транспорт'], // просрочки и частичные обзвоны (dashboard-stats-by-organization)
    ];

    private const POSITIONS = ['Генеральный директор', 'Руководитель отдела закупок', 'Руководитель отдела кадров'];

    /** Число контактов на каждую организацию (Ромашка, Вектор, Сидоров, Конкурент, Горизонт, Закат, Парус).
     *  У Вектора 4 — чтобы карточки переносились на новую строку (влияет на вид). */
    private const CONTACTS_PER_ORGANIZATION = [2, 4, 1, 2, 0, 1, 0];

    /** Имена контактов: ключ — глобальный индекс контакта (0..9).
     *  Имена не обязаны быть уникальными ни в организации, ни глобально. */
    private const CONTACT_NAMES = [
        'Иван Петрович Иванов',
        'Иван Иванович Петров',
        'Пётр Иванович',
        'Наталья Павловна Сидоровна',
        'Мария Сергеевна Петровна',
        'Марина Александровна',
        'Анна Сергеевна Иванова',
        'Дмитрий Николаевич',
        'Марина Александровна',
        'Ольга Викторовна',
    ];

    /** Должности: ключ — глобальный индекс контакта; для всех контактов разные. */
    private const POSITION_BY_INDEX = [
        0 => 'Генеральный директор',
        1 => 'Руководитель отдела кадров',
        2 => 'Директор по логистике',
        3 => 'Руководитель отдела закупок',
        4 => 'Приёмная',
        5 => 'Менеджер по логистике',
        6 => 'Директор',
        7 => 'Главный инженер',
        8 => 'Начальник производства',
        9 => 'Руководитель отдела продаж',
    ];

    /** Заметки контактов: ключ — локальный индекс контакта в организации. */
    private const CONTACT_NOTES = [
        0 => 'Предпочитает звонки после 14:00',
        2 => 'Запись через приёмную',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $admin = $this->makeUser($manager, self::ADMIN_EMAIL, self::ADMIN_PASSWORD, UserRole::Admin);
        $manager1 = $this->makeUser($manager, self::MANAGER_EMAIL, self::MANAGER_PASSWORD, UserRole::Manager);
        $manager2 = $this->makeUser($manager, self::SECOND_MANAGER_EMAIL, self::SECOND_MANAGER_PASSWORD, UserRole::Manager);
        $manager->flush();

        $group1 = $this->makeGroup(
            $manager,
            'Клиенты Ромашка',
            $manager1,
            'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
            '#20799e',
        );
        $group2 = $this->makeGroup($manager, 'Клиенты Вектор', $manager2, 'Логистические клиенты и перевозчики', '#5e9e47');
        $custom = $this->makeGroup($manager, 'Клиенты-партнёры', $admin, 'Общая база партнёров для всех менеджеров', '#d66a2b');
        // Пустая группа (без организаций) для проверки назначения: смена
        // флажков на странице «Назначить» не меняет область доступа (change
        // organization-group-assignment).
        $archive = $this->makeGroup($manager, 'Архивные клиенты', $admin, 'Группа без организаций: песочница для назначения менеджерам', '#8a8f98');
        $manager->flush();

        $manager->persist(new GroupAssignment($manager1, $custom));
        $manager->persist(new GroupAssignment($manager2, $custom));
        // Единичное назначение: на странице группы «Архивные клиенты» отмечен
        // ровно один менеджер, на страницах «Клиенты Ромашка»/«Клиенты Вектор» — ни одного.
        $manager->persist(new GroupAssignment($manager1, $archive));

        $organizations = [];
        foreach (self::ORGANIZATIONS as [$name, $industry]) {
            $organization = new Organization()
                ->setName($name)
                ->setIndustry($industry);
            $manager->persist($organization);
            $organizations[] = $organization;
        }
        $manager->flush();

        // created_by (change enhance-org-tables):管理者・admin を設定.
        $organizations[0]->setCreatedBy($manager1);
        $organizations[1]->setCreatedBy($manager1);
        $organizations[2]->setCreatedBy($manager1);
        $organizations[3]->setCreatedBy($manager2);
        $organizations[4]->setCreatedBy($admin);
        $organizations[5]->setCreatedBy($admin);
        $organizations[6]->setCreatedBy($manager1);

        // Примеры новых полей (change organization-fields-expansion).
        $organizations[0]->setAnnualPlan('Сентябрь 2026')->setDescription('Крупный ритейлер');
        $organizations[1]->setCoursesAttended('Курс по логистике');
        $organizations[2]->setDescription('Постоянный клиент');

        // УНП (change enhance-org-tables).
        $organizations[0]->setUnp('100123456');
        $organizations[1]->setUnp('100987654');

        // Примеры новых полей (change call-result-deal-and-optout):
        // Горизонт (индекс 4) — isActive = false;
        // Конкурент (индекс 3) — opted-out на прошлой неделе;
        // Закат (индекс 5) — opted-out из письма в текущем месяце.
        $organizations[4]->setIsActive(false);
        $organizations[3]->setIsOptedOut(true)
            ->setOptOutReason('Перестал отвечать на звонки')
            ->setOptedOutAt(new \DateTimeImmutable('-8 days'));
        $organizations[5]->setIsOptedOut(true)
            ->setOptOutReason('Отписка из письма')
            ->setOptedOutAt(new \DateTimeImmutable('-20 days'));

        $manager->persist(new OrgGroupMembership($organizations[0], $group1));
        $manager->persist(new OrgGroupMembership($organizations[1], $group1));
        $manager->persist(new OrgGroupMembership($organizations[1], $custom));
        $manager->persist(new OrgGroupMembership($organizations[2], $group2));
        $manager->persist(new OrgGroupMembership($organizations[2], $custom));
        $manager->persist(new OrgGroupMembership($organizations[3], $group2));
        $manager->persist(new OrgGroupMembership($organizations[4], $group1)); // Горизонт — без контактов
        $manager->persist(new OrgGroupMembership($organizations[5], $group1)); // Закат — с контактом, без звонков
        $manager->persist(new OrgGroupMembership($organizations[6], $group1)); // Парус — просрочки/частичные обзвоны

        // Скрытие организаций (change organization-hiding, ADR-0012):
        // «Конкурент» скрыт от manager1 — default-open, менеджер видит все
        // организации, кроме записей скрытия.
        $manager->persist(new OrganizationHide($organizations[3], $manager1));

        $contacts = [];
        $index = 0;
        foreach ($organizations as $orgIndex => $organization) {
            $count = self::CONTACTS_PER_ORGANIZATION[$orgIndex];
            for ($i = 0; $i < $count; ++$i) {
                $isReception = $orgIndex === 1 && $index === 4; // «Приёмная» — Мария (3-й контакт Вектора)
                $contact = new Contact()
                    ->setOrganization($organization)
                    ->setName(self::CONTACT_NAMES[$index])
                    ->setPhone('+7 900 000-00-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT))
                    ->setEmail('contact' . $index . '@example.ru')
                    ->setPosition($isReception ? 'Приёмная' : self::POSITION_BY_INDEX[$index] ?? self::POSITIONS[0])
                    ->setNotes(self::CONTACT_NOTES[$i] ?? null);
                $manager->persist($contact);
                $contacts[] = $contact;
                ++$index;
            }
        }

        // Звонки. ООО "Ромашка" — три звонка с разными наборами данных:
        // первый без заметки и без контакта (только дата), следующий только
        // с заметкой (без контакта), следующий только с контактом (без
        // заметки). ООО "Горизонт" — без звонков вовсе. Вектор/Конкурент —
        // факты и планы с заметками; заметки — рабочие формулировки менеджера.
        $today = new \DateTimeImmutable('today');
        $yesterday = $today->modify('-1 day');
        $make = static function (Organization $org, ?Contact $contact, \DateTimeImmutable $madeAt, ?string $notes) use ($manager, $manager1): void {
            $call = new Call()
                ->setOrganization($org)
                ->setMadeAt($madeAt)
                ->setMadeBy($manager1)
                ->setNotes($notes);
            if (null !== $contact) {
                $call->setContact($contact);
            }
            $manager->persist($call);
        };
        $make($organizations[0], null, $today->modify('-10 days')->setTime(12, 0), null); // Ромашка: только дата
        $make($organizations[0], null, $today->modify('-3 days')->setTime(12, 0), 'Нет ответа, перезвонить завтра'); // Ромашка: только заметка
        $make($organizations[1], $contacts[2], $today->modify('-3 days')->setTime(12, 0), 'Уточнить состав группы');

        // Скрыт от менеджера (ООО "Конкурент" — запись organization_hide)
        $manager->persist(new Call()
            ->setOrganization($organizations[3])
            ->setContact($contacts[7])
            ->setMadeAt($today->setTime(10, 0))
            ->setMadeBy($manager2)
            ->setNotes('Нет ответа'));
        $manager->persist(new Call()
            ->setOrganization($organizations[3])
            ->setContact($contacts[8])
            // Планы «на сегодня» стоят на 00:05: окно waitingWeek/Month
            // считается строго > now, поэтому результат не зависит от
            // времени запуска (в waitingToday попадает в любом случае).
            ->setScheduledAt($today->setTime(0, 5))
            ->setMadeBy($manager2)
            ->setNotes('Перезвонить утром'));

        // Запланированные обзвоны (scheduled) по периодам дашборда:
        // неделя (+1д) / месяц (+20д у Конкурента) / более месяца (+45д);
        // -2д — просроченный план (без заметки).
        $plan = static function (Organization $org, ?Contact $contact, \DateTimeImmutable $at, ?string $notes) use ($manager, $manager1): void {
            $call = new Call()
                ->setOrganization($org)
                ->setScheduledAt($at)
                ->setMadeBy($manager1)
                ->setNotes($notes);
            if (null !== $contact) {
                $call->setContact($contact);
            }
            $manager->persist($call);
        };
        $plan($organizations[0], $contacts[0], $today->modify('+1 day')->setTime(10, 0), null); // Ромашка: только контакт
        $plan($organizations[1], $contacts[2], $today->modify('+1 day')->setTime(10, 0), 'Перезвонить после 14:00');
        $plan($organizations[2], null, $today->modify('+45 days')->setTime(10, 0), 'План обучения будет в ноябре, уточнить состав группы'); // Сидоров: последний звонок без контакта
        $plan($organizations[2], $contacts[6], $today->modify('-2 days')->setTime(16, 0), null);

        // Звонки «только с датой»: без заметки и без контакта — у Конкурента.
        // Даты видны в таблице; в списке «Все звонки» строки без текста
        // (контакта тоже нет).
        $bare = static function (Organization $org, \DateTimeImmutable $at, bool $made) use ($manager, $manager1): void {
            $call = new Call()
                ->setOrganization($org)
                ->setMadeBy($manager1);
            if ($made) {
                $call->setMadeAt($at);
            } else {
                $call->setScheduledAt($at);
            }
            $manager->persist($call);
        };
        $bare($organizations[3], $today->modify('-9 days')->setTime(12, 0), true); // Конкурент: факт
        $bare($organizations[3], $today->modify('+14 days')->setTime(11, 0), false); // Конкурент: план

        // Просроченные звонки (change dashboard-stats-by-organization): план
        // в прошлом с made_at IS NULL — категория «Просроченные». Вектор:
        // факт сегодня + план сегодня (исключается из «Ожидают сегодня») +
        // нереализованный план вчера («Просроченные: вчера» + факт сегодня —
        // независимые категории). Сидоров/Конкурент: просрочки глубиной
        // 20 и 5 дней. Парус: 5 планов на вчера (3 совершены, 2 нет) —
        // частичная реализация, плюс план на сегодня без факта сегодня.
        $bare($organizations[1], $today->setTime(8, 0), true); // Вектор: факт сегодня
        $bare($organizations[1], $today->setTime(0, 5), false); // Вектор: план сегодня (исключён фактом из «Ожидают»)
        $plan($organizations[1], null, $yesterday->setTime(11, 0), null); // Вектор: просрочка вчера
        $bare($organizations[2], $today->modify('-20 days')->setTime(14, 0), false); // Сидоров: просрочка за 30 дней
        $bare($organizations[3], $today->modify('-5 days')->setTime(12, 0), false); // Конкурент: просрочка скрыта от менеджера
        for ($i = 0; $i < 5; ++$i) {
            // Парус: 5 планов на вчера — 3 совершены (план + факт), 2 нет
            $call = new Call()
                ->setOrganization($organizations[6])
                ->setScheduledAt($yesterday->setTime(18, $i))
                ->setMadeBy($manager1);
            if ($i < 3) {
                $call->setMadeAt($yesterday->setTime(19, $i));
            }
            $manager->persist($call);
        }
        $bare($organizations[6], $today->setTime(0, 5), false); // Парус: план сегодня без факта → waiting1

        // ── Рассылки (campaigns) — все статусы ────────────────────────

        // Черновик.
        $campaignDraft = new Campaign()
            ->setName('Новые курсы')
            ->setSubject('Приглашаем на курсы 2026')
            ->setPreviewText('Обзор новых курсов для ваших сотрудников')
            ->setBody('<p>{{greeting}}!</p><p>Приглашаем вас на наши курсы.</p><p>С уважением,<br>команда обучения.</p>');
        $manager->persist($campaignDraft);

        // Готова.
        $campaignReady = new Campaign()
            ->setName('Осенняя рассылка')
            ->setSubject('Осень на носу — готовьте сотрудников')
            ->setBody('<p>{{greeting}}!</p><p>Осень — время обновлений. Предлагаем вам наши программы.</p>');
        $campaignReady->setStatus(CampaignStatus::Ready);
        $manager->persist($campaignReady);

        // Запущена.
        $campaignLaunched = new Campaign()
            ->setName('Акция')
            ->setSubject('Скидки недели')
            ->setPreviewText('Специальные предложения только для вас')
            ->setBody(
                '<p>{{greeting}}!</p>'
                . '<p>Специальное предложение только для вас.</p>'
                . '<table><tbody><tr>'
                . '<td style="background-color: #fef3c7; padding: 8px 12px;"><strong>Скидка 20%</strong></td>'
                . '<td style="padding: 8px 12px;">по 31 декабря</td>'
                . '</tr></tbody></table>'
                . '<p><img src="https://trainingcenter.by/wp-content/themes/training-center-by/img/icons/logo.svg" alt="Баннер курсов" width="100"></p>'
                . '<p>Не пропустите скидки этой недели!</p>',
            )
            ->setStatus(CampaignStatus::Launched);
        $campaignLaunched->launch();
        $manager->persist($campaignLaunched);

        // Ошибка.
        $campaignFailed = new Campaign()
            ->setName('Рассылка с ошибкой')
            ->setSubject('Тестовая ошибка отправки')
            ->setBody('<p>{{greeting}}!</p><p>Это тестовая рассылка для проверки обработки ошибок.</p>');
        $campaignFailed->fail();
        $manager->persist($campaignFailed);

        // Архив.
        $campaignArchived = new Campaign()
            ->setName('Прошлая акция')
            ->setSubject('Акция прошла')
            ->setBody('<p>{{greeting}}!</p><p>Это архивная рассылка.</p>')
            ->setStatus(CampaignStatus::Archived);
        $manager->persist($campaignArchived);

        // Ещё один черновик.
        $campaignStandalone = new Campaign()
            ->setName('Приглашение на вебинар')
            ->setSubject('Вебинар по логистике')
            ->setPreviewText('Приглашение на вебинар')
            ->setBody(
                '<p>{{greeting}}!</p>'
                . '<p>Приглашаем на вебинар {{organization_name}}.</p>'
                . '<p>Тема: Современная логистика.</p>',
            );
        $manager->persist($campaignStandalone);

        $manager->flush();

        // Вложения кампании — метаданные + реальные файлы в storage.
        $storageDir = $this->projectDir . '/var/storage/campaign-attachments';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }

        $attachment1 = new CampaignAttachment($campaignLaunched, 'брошюра.pdf', 'fixture-broshure-001');
        $attachment1->setMimeType('application/pdf')->setSize(204800);
        $manager->persist($attachment1);
        @file_put_contents($storageDir . '/fixture-broshure-001', 'fixture pdf content');

        $attachment2 = new CampaignAttachment($campaignLaunched, 'прайс.xlsx', 'fixture-price-002');
        $attachment2->setMimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->setSize(51200);
        $manager->persist($attachment2);
        @file_put_contents($storageDir . '/fixture-price-002', 'fixture xlsx content');

        // Ручные адресаты standalone-рассылки.
        $manager->persist(new CampaignRecipient($campaignStandalone, $organizations[0])); // Ромашка — вся организация
        $manager->persist(new CampaignRecipient($campaignStandalone, $organizations[1], $contacts[2])); // Вектор → контакт

        // Адресаты для «Акция» (запущена) — несколько организаций, часть с контактами.
        $recipientLaunched1 = new CampaignRecipient($campaignLaunched, $organizations[0], $contacts[0]); // Ромашка → Иван
        $manager->persist($recipientLaunched1);

        $recipientLaunched2 = new CampaignRecipient($campaignLaunched, $organizations[1], $contacts[2]); // Вектор → Пётр
        $manager->persist($recipientLaunched2);

        $recipientLaunched3 = new CampaignRecipient($campaignLaunched, $organizations[2]); // Сидоров — вся организация
        $manager->persist($recipientLaunched3);

        $recipientLaunched4 = new CampaignRecipient($campaignLaunched, $organizations[5], $contacts[9]); // Закат → Ольга
        $manager->persist($recipientLaunched4);

        // Адресаты для «Осенняя рассылка» (готова) — все доступные организации.
        $manager->persist(new CampaignRecipient($campaignReady, $organizations[0], $contacts[1])); // Ромашка → Иван Иванович
        $manager->persist(new CampaignRecipient($campaignReady, $organizations[1], $contacts[3])); // Вектор → Наталья
        $manager->persist(new CampaignRecipient($campaignReady, $organizations[2], $contacts[6])); // Сидоров → Анна
        $manager->persist(new CampaignRecipient($campaignReady, $organizations[3], $contacts[7])); // Конкурент → Дмитрий
        $manager->persist(new CampaignRecipient($campaignReady, $organizations[4])); // Горизонт — вся организация

        // Адресаты для «Новые курсы» (черновик) — две организации.
        $manager->persist(new CampaignRecipient($campaignDraft, $organizations[0])); // Ромашка
        $manager->persist(new CampaignRecipient($campaignDraft, $organizations[1], $contacts[5])); // Вектор → Марина

        // Адресаты для архивной рассылки (только просмотр).
        $manager->persist(new CampaignRecipient($campaignArchived, $organizations[0], $contacts[0])); // Ромашка → Иван
        $manager->persist(new CampaignRecipient($campaignArchived, $organizations[3])); // Конкурент — вся организация

        // ── Звонки с комбинациями результата (change call-result) ────────

        // Письмо + следующий звонок (Ромашка → Осенняя рассылка + будущий звонок).
        $callMailingNext = new Call()
            ->setOrganization($organizations[0])
            ->setContact($contacts[0])
            ->setMadeAt($today->modify('-5 days')->setTime(11, 0))
            ->setMadeBy($manager1)
            ->setNotes('Отправили осеннюю рассылку, перезвонить')
            ->setCampaign($campaignReady);
        $manager->persist($callMailingNext);
        $nextFromMailing = new Call()
            ->setOrganization($organizations[0])
            ->setContact($contacts[0])
            ->setScheduledAt($today->modify('+5 days')->setTime(10, 0));
        $manager->persist($nextFromMailing);
        $callMailingNext->setNextCall($nextFromMailing);

        // Нет ответа + письмо (Вектор → запущенная «Акция»).
        $manager->persist(new Call()
            ->setOrganization($organizations[1])
            ->setContact($contacts[2])
            ->setMadeAt($today->modify('-2 days')->setTime(15, 0))
            ->setMadeBy($manager1)
            ->setIsNoAnswer(true)
            ->setCampaign($campaignLaunched)
            ->setNotes('Не взяли трубку, отправили акцию'));

        // Сделка (Сидоров).
        $manager->persist(new Call()
            ->setOrganization($organizations[2])
            ->setContact($contacts[6])
            ->setMadeAt($today->modify('-1 day')->setTime(16, 0))
            ->setMadeBy($manager1)
            ->setIsDeal(true)
            ->setNotes('Договорились о курсе'));

        // Отказ (Ромашка).
        $manager->persist(new Call()
            ->setOrganization($organizations[0])
            ->setContact($contacts[0])
            ->setMadeAt($today->modify('-2 days')->setTime(14, 30))
            ->setMadeBy($manager1)
            ->setIsRefusal(true)
            ->setNotes('Отказались от сотрудничества'));

        $manager->flush();
    }

    private function makeUser(ObjectManager $manager, string $email, string $password, UserRole $role): User
    {
        $login = explode('@', $email)[0];
        $user = new User()
            ->setLogin($login)
            ->setEmail($email)
            ->setRole($role);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $manager->persist($user);

        return $user;
    }

    private function makeGroup(ObjectManager $manager, string $name, User $createdBy, ?string $description = null, ?string $color = null): OrganizationGroup
    {
        $group = new OrganizationGroup()
            ->setName($name)
            ->setDescription($description)
            ->setColor($color)
            ->setCreatedBy($createdBy);
        $manager->persist($group);

        return $group;
    }
}
