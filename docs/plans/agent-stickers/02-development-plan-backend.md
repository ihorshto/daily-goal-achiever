# План розробки (backend): AI-агент надсилає стікери та емодзі

- task_slug: `agent-stickers`
- aspect: backend
- Джерела: `_brief.md` (рішення користувача від 2026-11-24 мають пріоритет), `01-business-analysis.md`
- Гілка: `agent-stickers` → PR у `main`
- Стиль комітів: Conventional Commits, **без** AI/Claude-трейлерів (явна інструкція користувача в брифі)

## 0. Зафіксовані рішення користувача (перекривають OQ у BA)

| # | Рішення | Як відображено в плані |
|---|---|---|
| 1 | Два окремі перемикачі: `/stickers on\|off` і `/emoji on\|off` (також природною мовою й у `/settings`), обидва ON за замовчуванням | `user_settings.stickers_enabled`, `user_settings.emoji_enabled` (bool, default true); команди в `CommandHandler`; поля в `update_notification_preferences`; рядки в `/settings` |
| 2 | Проактивні повідомлення: **лише емодзі**. Стікери тільки у відповідях на повідомлення користувача | `PromptManager::emojiRule()` додається в `ProactivePlanner`; `send_sticker` доступний лише при `trigger=user_message` (`availableFor()` + `USER_TURN_ONLY` + `mutates=true`) |
| 3 | Ліміти фіксовані в конфігу (не змінюються з чату): ≤1 стікер на відповідь, ≥30 хв **і** ≥3 відповіді між стікерами, ≤6 на день | `config/companion.php` → секція `stickers` (env-overridable оператором); ≤1 на відповідь забезпечує unique(`sticker_sends.source_message_id`) |

Відкриті питання BA OQ-4..OQ-6 закриваю рекомендаціями BA: пропозиція «Додати набір?» на кожен новий набір, не частіше 1 разу на 30 днів; реакції не у v1; максимум 10 наборів на користувача.

## 1. Виявлена форма проєкту

- Laravel 13 (`laravel/framework ^13.17`), PHP ^8.3, PHPUnit 12, Pint. PHPStan-конфігу в репозиторії немає.
- Runner: `docker compose exec -T app`, тести: `php artisan test`, лінт: `./vendor/bin/pint --test`.
- **Фронтенду немає**: Inertia/Vue не використовуються (`resources/js/app.js` — порожній скелет). Інтерфейс продукту — Telegram-бот. Маршрути `web.php`/`api.php` мають лише webhook і health.
- Архітектура: `TelegramUpdateRouter` (без мережевих викликів inline) → черга (`ProcessInboundMessage`, `HandleCallbackQuery`) → `AgentOrchestrator` (tools + `ToolExecutor` з ідемпотентністю) → `OutboundMessenger` (exactly-once через `notification_log`).
- Валідація: `Tool::rules()` (аналог Form Request для tool calls). Авторизація: `ActionAuthorizationPolicy` + запити, обмежені `user_id`. HTTP-контролери, Form Requests і Laravel Policies ця фіча **не додає**: нових HTTP-ендпоінтів немає. Це свідомий вибір у межах наявних патернів, а не обхід конвенцій.

## 2. Огляд рішення

```
Вхідний стікер ──► Router ──► Message(source=telegram_sticker, "[sticker 😂]")
                     │
                     ├─ набір невідомий/давно запропонований ──► OfferStickerSet (job)
                     │        getStickerSet → sticker_sets(status=offered) → "Додати «X» (N)?" [Додати][Не зараз]
                     │        не можна додати (404/custom_emoji/помилка) → fallback: ProcessInboundMessage
                     └─ інакше ──► ProcessInboundMessage (звичайний хід агента)

t.me/addstickers/X  або  /stickers add X|sync N ──► ImportStickerSet (job) ──► StickerCatalog::import
Кнопка k:{setId}:a  ──► CallbackQueryHandler ──► StickerCatalog::import ; k:{setId}:n → dismissed

Хід агента: send_sticker(emoji) ──► StickerLimiter (вимкнено/кулдаун/ліміт/≤1) ──► StickerPicker
           ──► sticker_sends (unique source_message_id)
ProcessInboundMessage: Bus::chain[ SendTelegramMessage, ...SendCalendarInvite, SendSticker ]
SendSticker ──► OutboundMessenger::deliverSticker (notification_log "sticker:{id}")
           ──► Message(source=agent_sticker, "[sticker 😂]") для історії розмови
```

## 3. Файли для створення

| Файл | Тип | Призначення |
|---|---|---|
| `database/migrations/2026_11_24_000200_add_sticker_preferences_to_user_settings.php` | migration | `stickers_enabled` bool default true, `emoji_enabled` bool default true (після `tone`) |
| `database/migrations/2026_11_24_000300_create_sticker_sets_table.php` | migration (stub) | Набори в каталозі користувача, разом зі станом пропозиції |
| `database/migrations/2026_11_24_000400_create_stickers_table.php` | migration (stub) | Стікери набору |
| `database/migrations/2026_11_24_000500_create_sticker_sends_table.php` | migration (stub) | Черга та історія надісланих агентом стікерів |
| `app/Enums/StickerSetStatus.php` | enum | `Offered = 'offered'`, `Active = 'active'`, `Dismissed = 'dismissed'` |
| `app/Models/StickerSet.php` | model | `$fillable`, casts (`status` → enum, `enabled` bool, `sticker_count` int, `offered_at`/`dismissed_at`/`synced_at` immutable_datetime), `user()`, `stickers()`; scopes `active()`, `usable()` (active+enabled) |
| `app/Models/Sticker.php` | model | `$fillable`, casts (`is_animated`/`is_video` bool, `position` int, `invalid_at` immutable_datetime), `stickerSet()`, `user()`; scope `usable()` (`invalid_at` null + набір usable) |
| `app/Models/StickerSend.php` | model | `$fillable`, casts (`sent_at`/`failed_at` immutable_datetime, `telegram_message_id` int), `user()`, `sticker()`, `sourceMessage()` |
| `app/Support/EmojiKey.php` | support | `normalize(?string): ?string` прибирає U+FE0E/U+FE0F, ZWJ-хвости skin tone (U+1F3FB–1F3FF), керівні символи й пробіли та обрізає до 16 символів. Однаково застосовується до каталогу та аргументу моделі |
| `app/Stickers/StickerCatalog.php` | service | Каталог користувача: `parseSetName()`, `import()`, `shouldOffer()`, `recordOffer()`, `dismiss()`, `listFor()`, `setByPosition()`, `remove()`, `setEnabled()`, `summary()` (блок контексту), `hasUsable()` |
| `app/Stickers/StickerImport.php` | DTO (readonly) | Результат імпорту: `StickerSet $set`, `int $count`, `bool $created` |
| `app/Stickers/StickerSetUnavailable.php` | exception (extends `DomainException`) | `notFound`, `unsupportedType` (custom_emoji), `empty`, `limitReached`, `retryLater` (429/connection). Повідомлення англійською, як у решті `DomainException` |
| `app/Stickers/StickerLimiter.php` | service | `denialReason(User, Message $source, CarbonImmutable $now): ?string`: вимкнено / ≤1 на хід / кулдаун хвилин / кулдаун відповідей / добовий ліміт. Також `availableNow()` для блоку контексту |
| `app/Stickers/StickerPicker.php` | service | `pick(User, string $emojiKey): ?Sticker`: випадковий usable стікер із цим ключем, без останніх 5 надісланих, якщо є альтернатива. `availableEmojis(User)` |
| `app/Agent/Tools/SendStickerTool.php` | tool | `send_sticker` (див. контракт нижче) |
| `app/Jobs/SendSticker.php` | job | Доставка `StickerSend` (tries=8, backoff як `SendCalendarInvite`) |
| `app/Jobs/OfferStickerSet.php` | job | Пропозиція додати набір для вхідного стікера, з fallback у звичайний хід |
| `app/Jobs/ImportStickerSet.php` | job | Імпорт або ре-синк набору за посиланням чи командою, відповідь користувачу |

## 4. Файли для зміни

| Файл | Що змінюється і чому |
|---|---|
| `app/Telegram/TelegramApiClient.php` | `sendSticker(int $chatId, string $fileId): TelegramResult` і `getStickerSet(string $name): TelegramResult` через `call()`. Логи без змін (лише method + error_code) |
| `tests/Fakes/FakeTelegramApiClient.php` | `public array $stickerSets = []` (name → масив StickerSet API). `call()`: `getStickerSet` повертає набір або 400 `STICKERSET_INVALID`; `sendSticker` повертає `message_id`. Хелпер `stickers(): list<array>`. Потрібно, щоб QA міг тестувати; тести пише QA-фаза |
| `app/Telegram/OutboundMessenger.php` | `deliverSticker(User, string $fileId, string $kind, string $key): DeliveryOutcome`, семантика `deliverDocument` (`claim()`/`failed()`) |
| `app/Telegram/TelegramUpdateRouter.php` | (1) Гілка `$msg['sticker']`: записати вхідне `Message` (`TelegramSticker`, `"[sticker {emoji}]"`) і залежно від `StickerCatalog::shouldOffer()` поставити в чергу `OfferStickerSet` або `ProcessInboundMessage`. (2) Текст, що складається лише з посилання `t.me/addstickers/...` або `t.me/addemoji/...`: записати як `TelegramText` у стані `Processed` і поставити `ImportStickerSet` у чергу. Мережевих викликів inline немає |
| `app/Telegram/CommandHandler.php` | `stickers` (`list`/`on`/`off`/`add`/`remove N`/`enable N`/`disable N`/`sync N`) і `emoji` (`on`/`off`). Доповнити `/help`, `/settings`. `$reply` стає nullable: для `add`/`sync` відповідь надсилає `ImportStickerSet` з тим самим dedup `reply:{messageId}` |
| `app/Telegram/Keyboards.php` | `stickerOfferRow(StickerSet)` → `k:{setId}:a` / `k:{setId}:n`. Задокументувати формат у docblock |
| `app/Telegram/CallbackQueryHandler.php` | Гілка `^k:(\d{1,12}):([an])$` з перевіркою stale TTL, пошуком `$user->stickerSets()`, `recordPress`, `removeKeyboard`, `a` → `import()`, `n` → `dismiss()` |
| `app/Agent/Tools/Tool.php` | Новий метод інтерфейсу `availableFor(ToolContext $context): bool` (чи пропонувати інструмент моделі в цьому ході) |
| `app/Agent/Tools/BaseTool.php` | Реалізація за замовчуванням `availableFor()` → `true`. Усі 23 інструменти успадковують `BaseTool`, інших реалізацій `Tool` немає |
| `app/Agent/ToolRegistry.php` | `definitionsFor(ToolContext $context)` фільтрує через `availableFor()`. `definitions()` лишається (повний список) |
| `app/Agent/AgentOrchestrator.php` | `$tools = $this->registry->definitionsFor($ctx)` (один раз на хід) |
| `app/Agent/ActionAuthorizationPolicy.php` | `send_sticker` у `USER_TURN_ONLY` |
| `app/Agent/PromptManager.php` | `emojiRule(bool $enabled)` (static, спільний для chat і proactive), `stickerRule()` (лише коли інструмент доступний), нові параметри `system(..., bool $emojiEnabled = true, bool $stickersAvailable = false)`, `VERSION = '2026-11-24.2'` |
| `app/Agent/ContextBuilder.php` | Передавати `emoji_enabled` і доступність стікерів у `system()`. Додати system-блок `STICKER CATALOG` (лише коли `send_sticker` доступний): заголовки наборів у `untrusted`, `😂×4, 👍×2…` (≤60 ключів), `available_now: yes/no (reason)`. Обрізати через `TokenEstimator::fit` у межах бюджету (~150 токенів) |
| `app/Proactive/ProactivePlanner.php` | Додати `PromptManager::emojiRule($settings->emoji_enabled)` поруч із `toneRule`. Стікерів немає |
| `app/Jobs/ProcessInboundMessage.php` | Після відповіді: якщо `$reply->status !== 'completed'`, видалити ще не надіслані `sticker_sends` цього ходу. Ланцюжок: `SendTelegramMessage` → `SendCalendarInvite*` → `SendSticker` (за наявності) |
| `app/Domain/SettingsService.php` | Булеві `stickers_enabled`/`emoji_enabled` проходять наявним шляхом `fill()` з логуванням `preferences_changed`. `describe()` отримує `stickers` (`"on (2 sets)"` / `"off"`) та `emoji` (`"on"`/`"off"`) |
| `app/Models/UserSetting.php` | casts `stickers_enabled`, `emoji_enabled` → boolean |
| `app/Models/User.php` | `stickerSets()`, `stickers()`, `stickerSends()` (HasMany) |
| `app/Agent/Tools/UpdateNotificationPreferencesTool.php` | Поля `stickers_enabled`, `emoji_enabled` (`boolean`, nullable), приклади в description («stop sending stickers» → `stickers_enabled=false`; «no emojis please» → `emoji_enabled=false`) |
| `app/Enums/MessageSource.php` | `TelegramSticker = 'telegram_sticker'`, `AgentSticker = 'agent_sticker'` (обидва ≤24 символи — ліміт колонки) |
| `app/Providers/AppServiceProvider.php` | `Tools\SendStickerTool::class` у `TOOLS` |
| `app/Memory/PersonalDataEraser.php` | Для scope `all`: видалити `sticker_sends` → `stickers` → `sticker_sets` **до** `messages` |
| `app/Console/Commands/PruneData.php` | `sticker_sends` старші за `retention.sticker_sends_days` |
| `config/companion.php` | Секція `stickers` + `retention.sticker_sends_days` (див. §6) |

Документацію (`docs/architecture.md`, `docs/data-model.md`, `docs/telegram-setup.md`) оновлює docs-фаза. Тут лише фіксую, що їх треба оновити.

## 5. Модель даних (стаби; індекси, FK і обмеження доопрацьовує artisan-specialist)

**user_settings** (+2 колонки): `stickers_enabled` boolean default true, `emoji_enabled` boolean default true.

**sticker_sets**
- `id`, `user_id` (FK users, cascade), `name` string(64), `title` string(64), `sticker_type` string(16) (`regular`/`mask`), `status` string(16) (`offered`/`active`/`dismissed`), `enabled` boolean default true, `sticker_count` unsignedSmallInteger default 0, `offered_at`, `dismissed_at`, `synced_at` (timestampTz nullable), `timestampsTz`.
- Для specialist: unique(`user_id`, `name`); index(`user_id`, `status`).

**stickers**
- `id`, `sticker_set_id` (FK, cascade), `user_id` (FK users, cascade; денормалізовано), `file_id` string(255), `file_unique_id` string(64), `emoji` string(16) nullable (як прийшло з Telegram), `emoji_key` string(16) nullable (`EmojiKey::normalize`), `is_animated` bool, `is_video` bool, `position` unsignedSmallInteger, `invalid_at` timestampTz nullable, `timestampsTz`.
- Для specialist: unique(`sticker_set_id`, `file_unique_id`); index(`user_id`, `emoji_key`).
- Відхилення від BA: додано `emoji_key`, щоб пошук і агрегація для контексту працювали через індекс, а не через нормалізацію на льоту.

**sticker_sends**
- `id`, `user_id` (FK, cascade), `source_message_id` (FK messages, nullOnDelete), `sticker_id` (FK stickers, nullOnDelete), `emoji` string(16), `file_id` string(255) (знімок), `telegram_message_id` bigInteger nullable, `sent_at` timestampTz nullable, `failed_at` timestampTz nullable, `timestampsTz`.
- Для specialist: unique(`source_message_id`) — це «≤1 стікер на хід» на рівні БД (NULL не конфліктують, тому після видалення повідомлення історія лишається); index(`user_id`, `created_at`).
- Відхилення від BA: `failed_at`, щоб постійна помилка доставки не висіла як «у черзі».

**notification_log**: новий `kind = 'sticker'`, ключ `sticker:{sticker_send_id}`. Схема не змінюється.

**messages**: нові значення `source`. Схема не змінюється.

## 6. Конфіг (`config/companion.php`)

```php
'stickers' => [
    'min_gap_minutes' => (int) env('STICKERS_MIN_GAP_MINUTES', 30),
    'min_replies_between' => (int) env('STICKERS_MIN_REPLIES_BETWEEN', 3),
    'max_per_day' => (int) env('STICKERS_MAX_PER_DAY', 6),
    'max_sets' => (int) env('STICKERS_MAX_SETS', 10),
    'offer_cooldown_days' => (int) env('STICKERS_OFFER_COOLDOWN_DAYS', 30),
    'catalog_max_emojis' => (int) env('STICKERS_CATALOG_MAX_EMOJIS', 60),
],
// retention:
'sticker_sends_days' => (int) env('RETENTION_STICKER_SENDS_DAYS', 90),
```

Значення за замовчуванням відповідають рішенню користувача №3. Ліміт «1 на відповідь» не винесено в конфіг: він структурний (unique-індекс), і користувач його зафіксував, тож перемикач, який нічого не може змінити, був би YAGNI. `.env` не чіпаю: усі змінні необов'язкові, з дефолтами.

## 7. Деталі реалізації за шарами

### 7.1 Telegram API
- `sendSticker`: payload `{chat_id, sticker: file_id}`.
- `getStickerSet`: payload `{name}`. 400 → `StickerSetUnavailable::notFound`, 429/0 (`connection_failed`) → `retryLater`.
- `OutboundMessenger::deliverSticker`: та сама логіка claim/failed, `Log::info('telegram.delivered', ['kind' => 'sticker', 'parts' => 1])`.

### 7.2 StickerCatalog
- `parseSetName(string $input): string`: приймає `^(?:https?://)?t\.me/addstickers/([A-Za-z0-9_]{1,64})/?$` або голу назву `^[A-Za-z0-9_]{1,64}$`. Для `t.me/addemoji/...` кидає `unsupportedType`, для решти — `DomainException('That is not a sticker set name or t.me/addstickers link.')`.
- `import(User, string $name): StickerImport`:
  1. Перевірка ліміту: кількість `active`-наборів (без цього `name`) ≥ `max_sets` → `limitReached`.
  2. `getStickerSet` → помилки як у §7.1. `sticker_type === 'custom_emoji'` → `unsupportedType`. Порожній `stickers[]` → `empty`.
  3. `DB::transaction`: `StickerSet::updateOrCreate(['user_id','name'], [title (одним рядком, ≤64), sticker_type, status=active, enabled=true, sticker_count, synced_at=now, dismissed_at=null])` з `try/catch QueryException` → повторний пошук (подвійне натискання). Далі `Sticker::upsert(rows, ['sticker_set_id','file_unique_id'], [file_id, emoji, emoji_key, is_animated, is_video, position, invalid_at=null, updated_at])` і видалення стікерів набору, яких немає у відповіді (ре-синк).
- `shouldOffer(User, string $setName): bool`: рядка немає → true. `active` → false. `offered`/`dismissed` → true лише якщо `offered_at`/`dismissed_at` старші за `offer_cooldown_days`.
- `recordOffer(User, array $apiSet): StickerSet`: upsert зі `status=offered`, `offered_at=now`, title, count. Стікери на цьому етапі **не** зберігаються, їх зберігає імпорт після [Додати].
- `summary(User): ?string`: usable стікери користувача, `groupBy emoji_key` з count (один агрегатний запит, без N+1), сортування за count desc, обрізання до `catalog_max_emojis`, а також заголовки увімкнених наборів.
- `listFor(User)`: `active`-набори, впорядковані за `id`. Номер у `/stickers` — це позиція в цьому списку. `setByPosition(User, int $n)` → `?StickerSet`.

### 7.3 StickerLimiter (порядок перевірок і тексти помилок для моделі)
1. `! stickers_enabled` → `Stickers are turned off by the user.`
2. Існує `sticker_sends` з `source_message_id = current` → `Only one sticker per reply.`
3. Останній send (за `created_at`) молодший за `min_gap_minutes` → `Sticker cooldown: the last one was N min ago; no sticker now.`
4. Кількість вхідних ходів користувача (`role=user`, source ∈ text/voice/sticker) з `last.source_message_id < id < current.id` менша за `min_replies_between` → `Sticker cooldown: too few replies since the last sticker; no sticker now.` Трактування «≥3 відповіді між стікерами»: між ходом попереднього стікера і поточним ходом має бути щонайменше 3 інші ходи. Якщо `source_message_id` попереднього = null (повідомлення видалено), перевіряються лише хвилини.
5. Кількість sends за поточну **локальну** добу користувача (`LocalTime`) ≥ `max_per_day` → `Daily sticker limit reached.`

Рахуються всі рядки `sticker_sends` (і в черзі, і надіслані), окрім видалених через непройдений хід (§7.6). Це консервативно: невдала доставка теж «з'їдає» ліміт. Ходи одного користувача серіалізовані через `WithoutOverlapping agent-user:{id}`, тому гонок між перевіркою і вставкою немає. На крайній випадок спрацює unique-індекс.

### 7.4 SendStickerTool (`send_sticker`)
- `mutates() = true`. `availableFor(ctx)` = `ctx->isUserTurn()` && `stickers_enabled` && `StickerCatalog::hasUsable(user)`.
- `handle()`: повторно перевіряє `stickers_enabled` (захист від replay/прямого виклику) → `StickerLimiter` → `EmojiKey::normalize($args['emoji'])` → `StickerPicker::pick()`. Якщо нічого не знайдено → `No sticker for that emoji; available: 😂, 👍, …` (≤30). Інакше `StickerSend::create(...)` у `try/catch QueryException` → `Only one sticker per reply.`
- Replay з `ToolExecutor` (той самий хід, ті самі аргументи) повертає збережений ok без нового рядка. Інший емодзі в тому самому ході відсікається перевіркою №2.

### 7.5 SendSticker (job)
- Завантажує `StickerSend` з `user`. Вихід без дій, якщо: рядка немає, `sent_at`/`failed_at` не null, користувач має `data_purged_at` після `created_at` або `stickers_enabled` тепер false.
- `deliverSticker(..., 'sticker', 'sticker:{id}')`.
  - sent → `telegram_message_id`, `sent_at`, плюс `ConversationService::recordAssistant($user, 'sticker:{id}', "[sticker {emoji}]", MessageSource::AgentSticker)`, щоб агент бачив власний стікер в історії, без окремої логіки в `ContextBuilder::history()`.
  - retry → `release(backoff)`.
  - permanent → `failed_at`. Якщо код 400, позначити `stickers.invalid_at`, щоб цей стікер більше не вибирався. Користувачу нічого не повідомляти.
- `deliverSticker` не надсилає `Message`, тож `SendTelegramMessage` не залучений. Історичний запис створюється лише після фактичної доставки.

### 7.6 ProcessInboundMessage
- Після `respond()`: якщо `status !== 'completed'` (llm_error/fallback/budget), то `StickerSend::where(source_message_id)->whereNull('sent_at')->delete()`, бо стікер після вибачення недоречний.
- Ланцюжок завжди будується однаково: `[SendTelegramMessage, ...invites, ...stickers]`. Якщо додаткових job-ів немає, `SendTelegramMessage::dispatch` як і раніше. Якщо доставка тексту остаточно не вдалася, ланцюжок зупиняється і стікер не йде (так і має бути).

### 7.7 Вхідні стікери (Router + OfferStickerSet)
- Router: `$sticker = $msg['sticker']`, якщо є `file_id`.
  - `emoji` очищується через `EmojiKey`-санітизацію (без нормалізації для показу, ≤16) → `text = "[sticker 😂]"` або `"[sticker]"`.
  - `recordInbound($user, $dedup, TelegramSticker, $text, $common)`. Наявний dedup `tg:{chat}:{msg}` обробляє повтори.
  - Якщо `$created`: при `set_name` є, `type !== 'custom_emoji'` і `shouldOffer()` → `OfferStickerSet::dispatch($message->id)`, інакше `ProcessInboundMessage::dispatch`.
- `OfferStickerSet` (`tries=3`, черга high, `WithoutOverlapping agent-user:`): перевіряє, що повідомлення ще в стані `Received` і `data_purged_at`. Робить `getStickerSet` за `set_name`, якого немає в `Message`, тому `set_name` передається в конструктор job разом з `messageId`.
  - ok і тип підтримується → `recordOffer` → `recordAssistant('reply:{msgId}', "Add the sticker set «{title}» ({N} stickers)? I'll sometimes use them in replies.", markup stickerOfferRow)` → `SendTelegramMessage`, повідомлення → `Processed`. LLM не викликається (AC-1).
  - notFound/unsupported/empty → `ProcessInboundMessage::dispatch` (стікер стає звичайною реплікою).
  - retryLater → `release()`. `failed()` → fallback у `ProcessInboundMessage`.
- Тексти бота англійською, як у всіх наявних командах і кнопках (`/tone`, `/settings`, «✔️ Confirm»). Мова UI-шаблонів у проєкті одна, тож не вводжу локалізацію в межах цієї фічі.

### 7.8 ImportStickerSet (job) і посилання
- Конструктор: `userId`, `rawInput` (назва/посилання), `sourceMessageId`. Перевірка purge.
- `StickerCatalog::import()` → `recordAssistant('reply:{sourceMessageId}', "Added «{title}»: {N} stickers. I'll use them now and then; /stickers off turns this off.")`. Якщо `stickers_enabled=false`, додати підказку «/stickers on to let me use them».
- `StickerSetUnavailable`/`DomainException` → текст помилки («Sticker set not found.», «Custom emoji sets can't be used by bots.», «You already have 10 sticker sets; remove one with /stickers remove N.»). `retryLater` → `release()`, а після вичерпання спроб «Telegram didn't answer, please try again in a minute.»

### 7.9 Команди
```
/stickers                → список: "1. My Cats — 24 stickers (on)", статус перемикача, підказка
/stickers on|off         → SettingsService::update(['stickers_enabled' => bool])
/stickers add <name|link>→ ImportStickerSet (відповідь із job)
/stickers sync <n>       → ImportStickerSet(set->name)
/stickers remove <n>     → видалити набір (cascade stickers; sticker_sends.sticker_id → null)
/stickers enable|disable <n> → sticker_sets.enabled
/emoji on|off            → SettingsService::update(['emoji_enabled' => bool])
```
Невалідний номер → `No sticker set #N. Send /stickers to see the list.` Для невідомої підкоманди показується підказка. `/settings` отримує рядки `- Stickers: on (2 sets)` і `- Emoji: on`. `/help` доповнюється `/stickers`, `/emoji`.

### 7.10 Промпт
- `emojiRule(true)`: `Emoji: you may add a fitting emoji now and then (most replies have none, never more than one or two). Never in sensitive moments: missed deadlines, stress or bad news, errors, data deletion. A user-chosen tone may narrow this (e.g. "no emojis"), never widen it.`
- `emojiRule(false)`: `Emoji: the user turned emojis off. Do not use any emoji in your messages, whatever the tone says.`
- `stickerRule()` (лише коли інструмент доступний): `Stickers: you can occasionally send one of the user's own stickers with send_sticker, picking an emoji from STICKER CATALOG, only when it really fits a light or warm moment (a joke, a win, a thank-you, a sticker the user sent). Most replies have no sticker. Never in sensitive moments. Call it before your final text; never mention or announce the sticker in your text. If it returns an error, just answer without one.`
- Пріоритет: прапорець `emoji_enabled=false` — жорстка заборона, tone може лише звузити дозвіл (рішення BA D4).

## 8. Порядок реалізації та залежності

1. Міграції (стаби) → enum `StickerSetStatus` → моделі `StickerSet`/`Sticker`/`StickerSend` → `User`/`UserSetting` (relations, casts) → `MessageSource`.
2. `config/companion.php` (секція `stickers`, retention).
3. `EmojiKey` → `TelegramApiClient` (+ Fake) → `OutboundMessenger::deliverSticker`.
4. `StickerSetUnavailable`, `StickerImport`, `StickerCatalog`, `StickerLimiter`, `StickerPicker`.
5. `Tool::availableFor` + `BaseTool` → `ToolRegistry::definitionsFor` → `AgentOrchestrator`.
6. `SendStickerTool` → `AppServiceProvider::TOOLS` → `ActionAuthorizationPolicy`.
7. `SettingsService` + `UpdateNotificationPreferencesTool`.
8. `PromptManager` → `ContextBuilder` → `ProactivePlanner`.
9. Jobs: `SendSticker`, `OfferStickerSet`, `ImportStickerSet` → `ProcessInboundMessage`.
10. Telegram UX: `Keyboards` → `CallbackQueryHandler` → `CommandHandler` → `TelegramUpdateRouter`.
11. GDPR/ретеншн: `PersonalDataEraser`, `PruneData`.
12. `php -l` для змінених файлів. Пересвідчитися, що `php artisan test` не ламає наявні тести (зокрема `ReplyToneTest` і `AgentScenariosTest` через зміну сигнатур `system()` і набору tools).

## 9. Ключові рішення та обґрунтування

1. **Фільтрація інструментів через `Tool::availableFor(ToolContext)`** з дефолтом `true` у `BaseTool` — мінімальна точка розширення (BA назвав це техборгом). Решта 23 інструменти не змінюються. Для користувача без каталогу набір tools залишається ідентичним (AC-13).
2. **Усі мережеві виклики Telegram (`getStickerSet`) — у queued jobs.** Router і `CommandHandler` працюють синхронно в polling-циклі на Pi, тож inline-виклик гальмував би всі оновлення. Тому `/stickers add` відповідає з `ImportStickerSet`.
3. **Стан пропозиції живе в `sticker_sets.status`**, а не в окремій таблиці. Один рядок на (user, name) з unique-індексом закриває дедуплікацію пропозицій, подвійне натискання [Додати] і кулдаун 30 днів. `callback_data` = `k:{setId}:{a|n}`: лише id, власника перевіряє `$user->stickerSets()`.
4. **Ліміти — детермінований `StickerLimiter` + unique(`source_message_id`)**, а промпт дає лише м'яке правило. Інструмент видно навіть при активному кулдауні, але в блоці каталогу є `available_now: no (reason)`, щоб модель не витрачала виклики даремно.
5. **Надісланий стікер записується як `Message(agent_sticker)` після доставки.** Історія розмови, проактивний `recent()`, `/deletemydata` і ретеншн працюють без спецлогіки.
6. **Моделі використовують явний `$fillable`** (вимога `laravel-conventions`/`eloquent-patterns`), хоча наявні моделі мають `$guarded = ['id']`. Це навмисне невелике відхилення від локального патерну, бо стек-конвенція вимагає `$fillable`. Якщо рев'юер наполягатиме на однаковості, заміна займе 1 рядок на модель.

## 10. Ризики та edge cases, які план покриває

| Ризик / кейс | Обробка |
|---|---|
| Повтор `ProcessInboundMessage` | Replay `tool_executions` + unique `source_message_id` + `notification_log` `sticker:{id}` → рівно один `sendSticker` (AC-5) |
| Хід завершився помилкою після `send_sticker` | Невідправлені sends видаляються (§7.6) |
| Користувач вимкнув стікери між ходом і доставкою | `SendSticker` перевіряє прапорець перед відправкою |
| `file_id` став невалідним (400) | `stickers.invalid_at`, без повторів, `StickerPicker` такі пропускає |
| `❤` проти `❤️`, skin tone | `EmojiKey::normalize` з обох боків |
| Набір `custom_emoji` / стікер без `set_name` | Посилання/команда → пояснення без збереження. Вхідний стікер → звичайна репліка, без пропозиції |
| Великі набори / багато наборів | `max_sets=10`, блок каталогу ≤60 ключів, обрізання `TokenEstimator::fit` |
| Чужі стікери | Усі запити через `$user->stickerSets()/stickers()`. Модель передає лише емодзі, `file_id` вибирає код (AC-10) |
| Prompt injection через title набору | Заголовки лише всередині `PromptManager::untrusted()`, одним рядком, ≤64 |
| `/deletemydata` під час пропозиції/імпорту | Jobs перевіряють `data_purged_at`. Callback після purge не знаходить набір («not available») |
| Зміна сигнатури `PromptManager::system()` | Нові параметри мають дефолти. Наявні тести, що перевіряють промпт, можуть потребувати оновлення: це зміна поведінки (новий рядок Emoji), а не регресія |
| Проактивні повідомлення | Лише `emojiRule`. Стікери неможливі за трьома незалежними бар'єрами (`availableFor`, `mutates`+proactive, `USER_TURN_ONLY`) (AC-14) |
| Кожен вхідний стікер запускає LLM-хід | Наявний `inbound_rate_per_minute` і бюджет. Свідомий наслідок FR-7 |

## 11. Конвенційні скіли для фази реалізації

- `laravel-plugin:laravel-conventions` — моделі (`$fillable`, casts, typed relations), jobs, конфіг.
- `laravel-plugin:eloquent-patterns` — upsert, агрегати без N+1 (`summary()`), атомарні оновлення, scopes `usable()`/`active()`.
- `php-foundation:php-conventions` — `declare(strict_types=1)`, `final readonly` DTO, backed enum, `match`.
- `php-foundation:php-testing` — лише щоб узгодити Fake-клієнт із тим, що писатиме QA.
- `php-foundation:composer-tooling` не потрібен: нових залежностей немає.

## 12. Нотатки для artisan-specialist (наступна фаза)

- unique(`sticker_sets.user_id`, `name`); index(`sticker_sets.user_id`, `status`).
- unique(`stickers.sticker_set_id`, `file_unique_id`); index(`stickers.user_id`, `emoji_key`).
- unique(`sticker_sends.source_message_id`); index(`sticker_sends.user_id`, `created_at`).
- FK: `stickers.sticker_set_id` cascade; `sticker_sends.sticker_id` і `source_message_id` nullOnDelete; усі `user_id` cascade.
- Фабрики `StickerSetFactory`, `StickerFactory` (для QA). `php artisan migrate` запускається у фазі `database`, не в цій.

## Contract for frontend

No frontend contract — this change adds no endpoints, props or payload shapes.

(Користувацький інтерфейс фічі — це Telegram-бот: команди `/stickers`, `/emoji`, inline-кнопки `k:{setId}:{a|n}` і агентний інструмент `send_sticker`. Усе це backend-код у цьому аспекті. HTTP-маршрутів, Inertia-сторінок чи JSON-ендпоінтів фіча не додає.)
