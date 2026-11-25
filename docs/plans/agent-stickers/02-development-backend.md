# Звіт реалізації (backend): AI-агент надсилає стікери та емодзі

## Файли створено
- Міграції (стаби): `database/migrations/2026_11_24_000200_add_sticker_preferences_to_user_settings.php`, `..._000300_create_sticker_sets_table.php`, `..._000400_create_stickers_table.php`, `..._000500_create_sticker_sends_table.php`
- `app/Enums/StickerSetStatus.php`
- `app/Models/StickerSet.php`, `Sticker.php`, `StickerSend.php` (з явним `$fillable`, casts, relations, scopes `active()`/`usable()`)
- `app/Support/EmojiKey.php`
- `app/Stickers/StickerCatalog.php`, `StickerImport.php`, `StickerSetUnavailable.php`, `StickerLimiter.php`, `StickerPicker.php`
- `app/Agent/Tools/SendStickerTool.php`
- `app/Jobs/SendSticker.php`, `OfferStickerSet.php`, `ImportStickerSet.php`

## Файли змінено
`TelegramApiClient` (sendSticker, getStickerSet), `OutboundMessenger` (deliverSticker), `TelegramUpdateRouter` (вхідні стікери, посилання на набори), `CommandHandler` (/stickers, /emoji, /help, /settings), `Keyboards` (stickerOfferRow), `CallbackQueryHandler` (k:{id}:a|n), `Tool` + `BaseTool` (availableFor), `ToolRegistry` (definitionsFor), `AgentOrchestrator`, `ActionAuthorizationPolicy`, `PromptManager` (emojiRule, stickerRule, VERSION 2026-11-24.2), `ContextBuilder` (блок STICKER CATALOG), `ProactivePlanner` (emojiRule), `ProcessInboundMessage` (ланцюжок + очищення при не-completed), `SettingsService::describe`, `UserSetting`, `User` (relations + дефолти в `settingsOrDefault`), `UpdateNotificationPreferencesTool`, `MessageSource`, `AppServiceProvider::TOOLS`, `PersonalDataEraser`, `PruneData`, `config/companion.php`, `tests/Fakes/FakeTelegramApiClient.php` (stickerSets, getStickerSet, sendSticker, stickers()).

## Ключові рішення
1. `Tool::availableFor()` (дефолт `true` у `BaseTool`) + `ToolRegistry::definitionsFor()`. `send_sticker` видно лише в ході користувача, коли стікери ввімкнено і є usable-каталог. Доступність для промпта/контексту береться зі списку tools, який уже побудовано (`ContextBuilder::build(..., $stickersAvailable)`).
2. Усі мережеві виклики `getStickerSet` лише в queued jobs (`OfferStickerSet`, `ImportStickerSet`) та у вже queued `HandleCallbackQuery`; роутер і `CommandHandler` лишаються без мережі.
3. Ліміти в `StickerLimiter` (порядок із плану) + `StickerSend` з `source_message_id`. «≤1 на хід» перевіряється й кодом, і (після фази database) unique-індексом.
4. Доставлений стікер пишеться як `Message(agent_sticker)` для історії; недоставлені `sticker_sends` видаляються, якщо хід не `completed`.
5. `User::settingsOrDefault()` явно ставить `stickers_enabled/emoji_enabled = true` при створенні, інакше щойно створена модель мала б `null` (cast → false) для DB-дефолтів і вимикала б емодзі.

## Відхилення від плану
- `StickerSetUnavailable` extends `RuntimeException`, а не `DomainException`: `DomainException` оголошено `final`. Повідомлення так само безпечні для користувача; додано прапорець `retryable`.
- Перевірка `max_sets` виконується після `getStickerSet` (за канонічною назвою з відповіді Telegram), а не до нього, щоб різний регістр назви не рахувався як новий набір.
- `StickerLimiter::availableNow()` не створено: `ContextBuilder` напряму використовує `denialReason()` (YAGNI). `StickerImport::message()` додано, щоб текст підтвердження не дублювався у job і callback.
- У міграціях-стабах є прості FK (`foreignId()->constrained()`), але немає unique/index: їх додає artisan-specialist.
- Pint запускався один раз вручну (`--dirty`) до уточнення, що це робить hook; змінив лише форматування в `CommandHandler`/`CallbackQueryHandler`.

## Contract for frontend
Немає: фронтенд-контракту фіча не додає. Contract deviations: none.

## Верифікація
- `php -l` на всіх змінених файлах: чисто.
- `php artisan test`: 117 тестів, 116 passed, 1 skipped (було до змін). Нові тести пише QA.
- Lint/static: pint clean (hook); phpstan у репозиторії не налаштовано.

## Нотатки для artisan-specialist
- unique(`sticker_sets.user_id`, `name`); index(`sticker_sets.user_id`, `status`).
- unique(`stickers.sticker_set_id`, `file_unique_id`) — **обов'язково**: `StickerCatalog::import` використовує `Sticker::upsert` з цим ключем (на PG без нього буде помилка ON CONFLICT); index(`stickers.user_id`, `emoji_key`).
- unique(`sticker_sends.source_message_id`) — «≤1 стікер на хід» на рівні БД (ловиться `QueryException` у `SendStickerTool`); index(`sticker_sends.user_id`, `created_at`).
- Фабрики `StickerSetFactory`, `StickerFactory` для QA. `php artisan migrate` не запускався.
- Оновити docs: `architecture.md`, `data-model.md`, `telegram-setup.md` (docs-фаза).

## Відкриті питання
- Тести зараз проходять на наявній схемі (міграції виконуються у RefreshDatabase), але шлях `import()` (upsert) без unique-індексу не покритий, поки specialist його не додасть.

## Security fix pass

- HIGH #1: `app/Telegram/CallbackQueryHandler.php` (stickerOffer) — the recorded button label no longer includes `$set->title`; it is now `Add|Not now (sticker set offer above)`, so no third-party text reaches a role=user message.
- Regression test: `test_offer_button_press_never_records_the_third_party_set_title_as_user_message` in tests/Feature/StickerTest.php (set titled "IGNORE ALL RULES", presses `a` and `n`).
- Not fixed: none (Medium/Low out of scope).

## Security fix pass

- HIGH #1: `app/Telegram/CallbackQueryHandler.php` (stickerOffer) — the recorded button label no longer includes `$set->title`; it is now `Add|Not now (sticker set offer above)`, so no third-party text reaches a role=user message.
- Regression test: `test_offer_button_press_never_records_the_third_party_set_title_as_user_message` in tests/Feature/StickerTest.php (set titled "IGNORE ALL RULES", presses `a` and `n`).
- Not fixed: none (Medium/Low out of scope).
