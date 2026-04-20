<?php
// @formatter:off
// phpcs:ignoreFile

/**
 * Laravel IDE Helper — Generated stub for Intelephense.
 *
 * Provides type information for Laravel facades, helpers, and Eloquent
 * so that static analysers (Intelephense, PHPStan, etc.) resolve symbols
 * without the actual runtime autoloader.
 *
 * This file is NOT loaded at runtime; it exists solely for IDE support.
 *
 * @see https://github.com/barryvdh/laravel-ide-helper
 */

namespace Illuminate\Contracts\Auth {

    interface Authenticatable {
        public function getAuthIdentifierName();
        public function getAuthIdentifier();
        public function getAuthPassword();
        public function getAuthPasswordName();
        public function getRememberToken();
        public function setRememberToken($value);
        public function getRememberTokenName();
    }

    interface CanResetPassword {
        public function getEmailForPasswordReset();
        public function sendPasswordResetNotification($token);
    }

    interface MustVerifyEmail {
        public function hasVerifiedEmail();
        public function markEmailAsVerified();
        public function sendEmailVerificationNotification();
        public function getEmailForVerification();
    }

    interface Guard {
        public function check();
        public function guest();
        public function user();
        public function id();
        public function validate(array $credentials = []);
        public function setUser(Authenticatable $user);
    }

    interface StatefulGuard extends Guard {
        public function attempt(array $credentials = [], $remember = false);
        public function once(array $credentials = []);
        public function login(Authenticatable $user, $remember = false);
        public function loginUsingId($id, $remember = false);
        public function onceUsingId($id);
        public function viaRemember();
        public function logout();
    }

    interface Factory {
        public function guard($name = null);
        public function shouldUse($name);
    }
}

namespace Illuminate\Contracts\View {
    interface Factory {}
    interface View {
        /**
         * @param string|array $key
         * @param mixed $value
         * @return static
         */
        public function with($key, $value = null);

        /**
         * @return string
         */
        public function render();
    }
}

namespace Illuminate\Contracts\Support {
    interface Arrayable {
        public function toArray();
    }
    interface Jsonable {
        public function toJson($options = 0);
    }
    interface Htmlable {
        public function toHtml();
    }
    interface Renderable {
        public function render();
    }
    interface Responsable {
        public function toResponse($request);
    }
}

namespace Illuminate\Contracts\Foundation {
    interface Application {
        /**
         * @param string|array ...$environments
         * @return string|bool
         */
        public function environment(...$environments);
    }
}

namespace Illuminate\Contracts\Broadcasting {
    interface Factory {}
}

namespace Illuminate\Contracts\Translation {
    interface Translator {}
}

namespace Illuminate\Contracts\Pagination {
    interface LengthAwarePaginator {}
    interface Paginator {}
}

namespace Illuminate\Contracts\Validation {
    interface Factory {}
    interface Validator {}
}

namespace Illuminate\Contracts\Cookie {
    interface Factory {}
}

namespace Illuminate\Database\Eloquent\Factories {

    trait HasFactory {
        /**
         * @return \Illuminate\Database\Eloquent\Factories\Factory
         */
        public static function factory($count = null, $state = []) {}
    }

    /**
     * @method static \Illuminate\Database\Eloquent\Factories\Factory count(int $count)
     * @method static \Illuminate\Database\Eloquent\Factories\Factory state(array|\Closure $state)
     * @method static \Illuminate\Database\Eloquent\Factories\Factory make(array $attributes = [], \Illuminate\Database\Eloquent\Model|null $parent = null)
     * @method static \Illuminate\Database\Eloquent\Factories\Factory create(array $attributes = [], \Illuminate\Database\Eloquent\Model|null $parent = null)
     */
    class Factory {}
}

namespace Illuminate\Notifications {

    trait Notifiable {
        /**
         * @param mixed $instance
         * @return void
         */
        public function notify($instance) {}

        /**
         * @param mixed $instance
         * @return void
         */
        public function notifyNow($instance, ?array $channels = null) {}

        /**
         * @return \Illuminate\Notifications\DatabaseNotificationCollection
         */
        public function notifications() {}

        /**
         * @return \Illuminate\Notifications\DatabaseNotificationCollection
         */
        public function unreadNotifications() {}

        /**
         * @param string|null $channel
         * @return string
         */
        public function routeNotificationFor($channel, $notification = null) {}
    }

    class DatabaseNotificationCollection {}
    class Notification {}
}

namespace Illuminate\Database\Eloquent\Relations {

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @mixin \Illuminate\Database\Eloquent\Builder
     */
    class Relation {
        /** @return \Illuminate\Database\Eloquent\Collection */
        public function get($columns = ['*']) {}
        /** @return TRelatedModel|null */
        public function first($columns = ['*']) {}
        /** @return \Illuminate\Database\Eloquent\Builder */
        public function getQuery() {}
        /** @return static */
        public function where($column, $operator = null, $value = null, $boolean = 'and') {}
        /** @return static */
        public function orderBy($column, $direction = 'asc') {}
        /** @return int */
        public function count() {}
        /** @return bool */
        public function exists() {}
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @extends Relation<TRelatedModel>
     */
    class BelongsTo extends Relation {
        /** @return TRelatedModel|null */
        public function first($columns = ['*']) {}
        public function associate($model) {}
        public function dissociate() {}
        public function getResults() {}
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @extends Relation<TRelatedModel>
     */
    class HasOne extends Relation {
        /** @return TRelatedModel|null */
        public function first($columns = ['*']) {}
        /** @return TRelatedModel */
        public function create(array $attributes = []) {}
        public function save($model) {}
        public function getResults() {}
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @extends Relation<TRelatedModel>
     */
    class HasMany extends Relation {
        /** @return \Illuminate\Database\Eloquent\Collection<int, TRelatedModel> */
        public function get($columns = ['*']) {}
        /** @return TRelatedModel */
        public function create(array $attributes = []) {}
        public function save($model) {}
        public function saveMany($models) {}
        public function getResults() {}
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @extends Relation<TRelatedModel>
     */
    class BelongsToMany extends Relation {
        /** @return \Illuminate\Database\Eloquent\Collection<int, TRelatedModel> */
        public function get($columns = ['*']) {}
        public function attach($id, array $attributes = [], $touch = true) {}
        public function detach($ids = null, $touch = true) {}
        public function sync($ids, $detaching = true) {}
        public function toggle($ids, $touch = true) {}
        public function getResults() {}
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @extends Relation<TRelatedModel>
     */
    class HasManyThrough extends Relation {
        public function getResults() {}
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @extends Relation<TRelatedModel>
     */
    class MorphTo extends BelongsTo {
        public function getResults() {}
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @extends Relation<TRelatedModel>
     */
    class MorphOne extends HasOne {
        public function getResults() {}
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @extends Relation<TRelatedModel>
     */
    class MorphMany extends HasMany {
        public function getResults() {}
    }
}

namespace Carbon {
    /**
     * @method static static parse(string|\DateTimeInterface|null $time = null, string|\DateTimeZone|null $tz = null)
     * @method static static now(string|\DateTimeZone|null $tz = null)
     * @method static static today(string|\DateTimeZone|null $tz = null)
     * @method static static create(int|null $year = 0, int|null $month = 1, int|null $day = 1, int|null $hour = 0, int|null $minute = 0, int|null $second = 0, string|\DateTimeZone|null $tz = null)
     * @method string format(string $format)
     * @method string toDateString()
     * @method string toDateTimeString()
     * @method static addDays(int $value = 1)
     * @method static subDays(int $value = 1)
     * @method static addMinutes(int $value = 1)
     * @method static subMinutes(int $value = 1)
     * @method static addMonths(int $value = 1)
     * @method static subMonths(int $value = 1)
     * @method static addYears(int $value = 1)
     * @method static addHours(int $value = 1)
     * @method static startOfDay()
     * @method static endOfDay()
     * @method static startOfMonth()
     * @method static endOfMonth()
     * @method bool isPast()
     * @method bool isFuture()
     * @method bool isToday()
     * @method bool isWeekend()
     * @method bool isWeekday()
     * @method int diffInDays(\DateTimeInterface|null $date = null, bool $absolute = true)
     * @method int diffInHours(\DateTimeInterface|null $date = null, bool $absolute = true)
     * @method int diffInMinutes(\DateTimeInterface|null $date = null, bool $absolute = true)
     * @method static startOfWeek(int $weekStartsAt = null)
     * @method static endOfWeek(int $weekEndsAt = null)
     * @method static startOfYear()
     * @method static endOfYear()
     * @method string toFormattedDateString()
     * @method string toFormattedDayDateString()
     * @method string diffForHumans(\DateTimeInterface|null $other = null, bool $absolute = false)
     * @method bool greaterThan(\DateTimeInterface $dt)
     * @method bool lessThan(\DateTimeInterface $dt)
     * @method bool equalTo(\DateTimeInterface $dt)
     * @method bool gte(\DateTimeInterface $dt)
     * @method bool lte(\DateTimeInterface $dt)
     * @method bool gt(\DateTimeInterface $dt)
     * @method bool lt(\DateTimeInterface $dt)
     * @method bool eq(\DateTimeInterface $dt)
     * @property int $year
     * @property int $month
     * @property int $day
     * @property int $hour
     * @property int $minute
     * @property int $second
     * @property int $dayOfWeek
     * @property int $dayOfYear
     * @property int $daysInMonth
     * @method static static createFromFormat(string $format, string $time, string|\DateTimeZone|null $timezone = null)
     */
    class Carbon extends \DateTimeImmutable {}

    class CarbonImmutable extends \DateTimeImmutable {}
    class CarbonInterval extends \DateInterval {}
    class CarbonPeriod implements \IteratorAggregate {
        /** @return \ArrayIterator */
        public function getIterator(): \ArrayIterator { return new \ArrayIterator([]); }
    }
}

namespace Illuminate\Database\Eloquent {

    /**
     * @method static \Illuminate\Database\Eloquent\Collection pluck(string|\Illuminate\Database\Query\Expression $column, string|null $key = null)
     */
    class Collection extends \Illuminate\Support\Collection {}
}

namespace Illuminate\Support {

    /**
     * @method static \Illuminate\Support\Carbon parse(string|\DateTimeInterface|null $time = null, string|\DateTimeZone|null $tz = null)
     * @method static \Illuminate\Support\Carbon now(string|\DateTimeZone|null $tz = null)
     * @method static \Illuminate\Support\Carbon today(string|\DateTimeZone|null $tz = null)
     * @method static \Illuminate\Support\Carbon create(int|null $year = 0, int|null $month = 1, int|null $day = 1, int|null $hour = 0, int|null $minute = 0, int|null $second = 0, string|\DateTimeZone|null $tz = null)
     * @method string format(string $format)
     * @method string toDateString()
     * @method string toDateTimeString()
     * @method \Illuminate\Support\Carbon addDays(int $value = 1)
     * @method \Illuminate\Support\Carbon subDays(int $value = 1)
     * @method \Illuminate\Support\Carbon addMinutes(int $value = 1)
     * @method bool isPast()
     * @method bool isFuture()
     * @method bool isToday()
     * @method int diffInDays(\DateTimeInterface|null $date = null, bool $absolute = true)
     *
     * @see \Carbon\Carbon
     */
    class Carbon extends \Carbon\Carbon {}

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @method static static make($items = [])
     * @method TValue|null first(callable|null $callback = null, $default = null)
     * @method TValue|null last(callable|null $callback = null, $default = null)
     * @method static map(callable $callback)
     * @method static filter(callable|null $callback = null)
     * @method static each(callable $callback)
     * @method static pluck(string $value, string|null $key = null)
     * @method bool isEmpty()
     * @method bool isNotEmpty()
     * @method int count()
     * @method array toArray()
     * @method static where(string $key, mixed $operator = null, mixed $value = null)
     * @method static sortBy(string|callable $callback, int $options = SORT_REGULAR, bool $descending = false)
     * @method static groupBy(string|callable $groupBy, bool $preserveKeys = false)
     * @method static unique(string|callable|null $key = null, bool $strict = false)
     * @method static values()
     * @method static keys()
     * @method static merge($items)
     * @method static push(...$values)
     * @method static put($key, $value)
     * @method mixed sum(callable|string|null $callback = null)
     * @method mixed avg(callable|string|null $callback = null)
     * @method mixed min(callable|string|null $callback = null)
     * @method mixed max(callable|string|null $callback = null)
     * @method bool contains($key, $operator = null, $value = null)
     * @method TValue|null get($key, $default = null)
     * @method static chunk(int $size)
     * @method static flatten(int $depth = INF)
     * @method static collapse()
     * @method string implode(string $value, ?string $glue = null)
     * @method static reject(callable|mixed $callback = true)
     * @method static take(int $limit)
     * @method static skip(int $count)
     * @method static slice(int $offset, ?int $length = null)
     * @method static keyBy(string|callable $keyBy)
     * @method static mapWithKeys(callable $callback)
     * @method static zip($items)
     * @method static combine($values)
     * @method static reverse()
     * @method static|TValue pop(int $count = 1)
     * @method static|TValue shift(int $count = 1)
     * @method TValue|null firstWhere(string $key, $operator = null, $value = null)
     * @method static forPage(int $page, int $perPage)
     */
    class Collection implements \ArrayAccess, \Countable, \IteratorAggregate {
        public function offsetExists($offset): bool { return false; }
        public function offsetGet($offset): mixed { return null; }
        public function offsetSet($offset, $value): void {}
        public function offsetUnset($offset): void {}
        public function count(): int { return 0; }
        public function getIterator(): \ArrayIterator { return new \ArrayIterator([]); }
    }

    class Str {
        /** @return string */
        public static function slug(string $title, string $separator = '-', ?string $language = 'en') {}
        /** @return string */
        public static function random(int $length = 16) {}
        /** @return string */
        public static function uuid() {}
        /** @return string */
        public static function orderedUuid() {}
        /** @return string */
        public static function upper(string $value) {}
        /** @return string */
        public static function lower(string $value) {}
        /** @return string */
        public static function title(string $value) {}
        /** @return string */
        public static function camel(string $value) {}
        /** @return string */
        public static function studly(string $value) {}
        /** @return string */
        public static function snake(string $value, string $delimiter = '_') {}
        /** @return bool */
        public static function contains(string $haystack, $needles, bool $ignoreCase = false) {}
        /** @return bool */
        public static function startsWith(string $haystack, $needles) {}
        /** @return bool */
        public static function endsWith(string $haystack, $needles) {}
        /** @return string */
        public static function limit(string $value, int $limit = 100, string $end = '...') {}
        /** @return string|array */
        public static function replace($search, $replace, $subject) {}
        /** @return string */
        public static function replaceFirst(string $search, string $replace, string $subject) {}
        /** @return string */
        public static function after(string $subject, string $search) {}
        /** @return string */
        public static function before(string $subject, string $search) {}
        /** @return string */
        public static function between(string $subject, string $from, string $to) {}
        /** @return bool */
        public static function is($pattern, string $value) {}
    }

    /**
     * @see \Illuminate\Support\HtmlString
     */
    class HtmlString {
        public function __construct(string $html = '') {}
        /** @return string */
        public function toHtml() {}
    }

    class LazyCollection {}
}

namespace Illuminate\Support\Facades {

    /**
     * @method static \Illuminate\Contracts\Auth\Authenticatable|null user()
     * @method static int|string|null id()
     * @method static bool check()
     * @method static bool guest()
     * @method static bool attempt(array $credentials = [], bool $remember = false)
     * @method static void login(\Illuminate\Contracts\Auth\Authenticatable $user, bool $remember = false)
     * @method static void logout()
     * @method static bool validate(array $credentials = [])
     * @method static \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard guard(string|null $name = null)
     *
     * @see \Illuminate\Auth\AuthManager
     */
    class Auth {}

    /**
     * @method static \Illuminate\Database\Query\Builder table(string $table, string|null $as = null)
     * @method static \Illuminate\Database\Query\Expression raw(mixed $value)
     * @method static mixed selectOne(string $query, array $bindings = [], bool $useReadPdo = true)
     * @method static array select(string $query, array $bindings = [], bool $useReadPdo = true)
     * @method static bool insert(string $query, array $bindings = [])
     * @method static int update(string $query, array $bindings = [])
     * @method static int delete(string $query, array $bindings = [])
     * @method static bool statement(string $query, array $bindings = [])
     * @method static mixed transaction(\Closure $callback, int $attempts = 1)
     * @method static void beginTransaction()
     * @method static void commit()
     * @method static void rollBack(int|null $toLevel = null)
     * @method static int transactionLevel()
     * @method static string getTablePrefix()
     * @method static \Illuminate\Database\Connection connection(string|null $name = null)
     *
     * @see \Illuminate\Database\DatabaseManager
     */
    class DB {}

    /**
     * @method static bool has(string $key)
     * @method static mixed get(string $key, mixed $default = null)
     * @method static bool put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null)
     * @method static bool forget(string $key)
     * @method static bool flush()
     * @method static mixed remember(string $key, \DateTimeInterface|\DateInterval|int|null $ttl, \Closure $callback)
     * @method static mixed rememberForever(string $key, \Closure $callback)
     * @method static \Illuminate\Cache\CacheManager store(string|null $name = null)
     *
     * @see \Illuminate\Cache\CacheManager
     */
    class Cache {}

    /**
     * @method static \Illuminate\Mail\SentMessage|null to(mixed $users, string|null $name = null)
     * @method static \Illuminate\Mail\SentMessage|null send(\Illuminate\Contracts\Mail\Mailable|string|array $view, array $data = [], \Closure|string|null $callback = null)
     * @method static \Illuminate\Mail\PendingMail cc(mixed $users, string|null $name = null)
     * @method static \Illuminate\Mail\PendingMail bcc(mixed $users, string|null $name = null)
     * @method static void raw(string $text, mixed $callback)
     * @method static \Illuminate\Mail\Mailer mailer(string|null $name = null)
     *
     * @see \Illuminate\Mail\MailManager
     */
    class Mail {}

    /**
     * @method static void emergency(string|\Stringable $message, array $context = [])
     * @method static void alert(string|\Stringable $message, array $context = [])
     * @method static void critical(string|\Stringable $message, array $context = [])
     * @method static void error(string|\Stringable $message, array $context = [])
     * @method static void warning(string|\Stringable $message, array $context = [])
     * @method static void notice(string|\Stringable $message, array $context = [])
     * @method static void info(string|\Stringable $message, array $context = [])
     * @method static void debug(string|\Stringable $message, array $context = [])
     * @method static void log(mixed $level, string|\Stringable $message, array $context = [])
     * @method static \Psr\Log\LoggerInterface channel(string|null $channel = null)
     * @method static \Psr\Log\LoggerInterface stack(array $channels, string|null $channel = null)
     *
     * @see \Illuminate\Log\LogManager
     */
    class Log {}

    /**
     * @method static \Illuminate\Routing\Route get(string $uri, array|string|callable|null $action = null)
     * @method static \Illuminate\Routing\Route post(string $uri, array|string|callable|null $action = null)
     * @method static \Illuminate\Routing\Route put(string $uri, array|string|callable|null $action = null)
     * @method static \Illuminate\Routing\Route patch(string $uri, array|string|callable|null $action = null)
     * @method static \Illuminate\Routing\Route delete(string $uri, array|string|callable|null $action = null)
     * @method static \Illuminate\Routing\Route options(string $uri, array|string|callable|null $action = null)
     * @method static \Illuminate\Routing\Route any(string $uri, array|string|callable|null $action = null)
     * @method static \Illuminate\Routing\Route match(array|string $methods, string $uri, array|string|callable|null $action = null)
     * @method static \Illuminate\Routing\Route fallback(array|string|callable|null $action = null)
     * @method static \Illuminate\Routing\Router middleware(array|string $middleware)
     * @method static \Illuminate\Routing\RouteRegistrar prefix(string $prefix)
     * @method static \Illuminate\Routing\RouteRegistrar name(string $name)
     * @method static \Illuminate\Routing\RouteRegistrar namespace(string|null $namespace)
     * @method static \Illuminate\Routing\Router group(array|\Closure|string $attributes, \Closure|array|string $routes = null)
     * @method static \Illuminate\Routing\Route resource(string $name, string $controller, array $options = [])
     * @method static \Illuminate\Routing\Route apiResource(string $name, string $controller, array $options = [])
     * @method static \Illuminate\Routing\RouteRegistrar domain(string $domain)
     * @method static string|null currentRouteName()
     * @method static \Illuminate\Routing\Route|null current()
     *
     * @see \Illuminate\Routing\Router
     */
    class Route {}

    /**
     * @method static bool has(string $key)
     * @method static bool hasTable(string $table)
     * @method static bool hasColumn(string $table, string $column)
     * @method static bool hasColumns(string $table, array $columns)
     * @method static void create(string $table, \Closure $callback)
     * @method static void table(string $table, \Closure $callback)
     * @method static void drop(string $table)
     * @method static void dropIfExists(string $table)
     * @method static void rename(string $from, string $to)
     * @method static array getColumnListing(string $table)
     *
     * @see \Illuminate\Database\Schema\Builder
     */
    class Schema {}

    /**
     * @method static string path(string $path = '')
     * @method static string disk(string|null $name = null)
     * @method static bool exists(string $path)
     * @method static string|null get(string $path)
     * @method static bool put(string $path, string|resource $contents, mixed $options = [])
     * @method static bool delete(string|array $paths)
     * @method static string url(string $path)
     *
     * @see \Illuminate\Filesystem\FilesystemManager
     */
    class Storage {}

    /**
     * @method static string encryptString(string $value)
     * @method static string decryptString(string $payload)
     * @method static string encrypt(mixed $value, bool $serialize = true)
     * @method static mixed decrypt(string $payload, bool $unserialize = true)
     *
     * @see \Illuminate\Encryption\Encrypter
     */
    class Crypt {}

    /**
     * @method static \Illuminate\Support\Facades\Hash check(string $value, string $hashedValue, array $options = [])
     * @method static string make(string $value, array $options = [])
     * @method static bool needsRehash(string $hashedValue, array $options = [])
     *
     * @see \Illuminate\Hashing\HashManager
     */
    class Hash {}

    /**
     * @method static bool check(\DateTimeInterface|string $abilities, array|mixed $arguments = [])
     * @method static \Illuminate\Auth\Access\Response authorize(string $ability, array|mixed $arguments = [])
     * @method static \Illuminate\Auth\Access\Gate define(string $ability, callable|string $callback)
     * @method static bool allows(string $ability, array|mixed $arguments = [])
     * @method static bool denies(string $ability, array|mixed $arguments = [])
     *
     * @see \Illuminate\Auth\Access\Gate
     */
    class Gate {}
}

namespace Illuminate\Database\Eloquent {

    /**
     * @method static static|null find(mixed $id, array $columns = ['*'])
     * @method static static findOrFail(mixed $id, array $columns = ['*'])
     * @method static static|null first(array $columns = ['*'])
     * @method static static firstOrFail(array $columns = ['*'])
     * @method static static firstOrNew(array $attributes = [], array $values = [])
     * @method static static firstOrCreate(array $attributes = [], array $values = [])
     * @method static static updateOrCreate(array $attributes, array $values = [])
     * @method static static create(array $attributes = [])
     * @method static bool forceCreate(array $attributes)
     * @method static \Illuminate\Database\Eloquent\Collection|static[] get(array $columns = ['*'])
     * @method static \Illuminate\Database\Eloquent\Collection|static[] all(array $columns = ['*'])
     * @method static int count(string $columns = '*')
     * @method static bool exists()
     * @method static bool doesntExist()
     * @method static \Illuminate\Database\Eloquent\Builder|static where(string|\Closure|array $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static orWhere(string|\Closure|array $column, mixed $operator = null, mixed $value = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereNotIn(string $column, mixed $values, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereNull(string|array $columns, string $boolean = 'and', bool $not = false)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereNotNull(string|array $columns, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereBetween(string|\Illuminate\Database\Query\Expression $column, iterable $values, string $boolean = 'and', bool $not = false)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereDate(string $column, string $operator, \DateTimeInterface|string|null $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereHas(string $relation, \Closure|null $callback = null, string $operator = '>=', int $count = 1)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereDoesntHave(string $relation, \Closure|null $callback = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereRaw(string $sql, mixed $bindings = [], string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static with(string|array $relations, string|\Closure|null $callback = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static withCount(string|array $relations)
     * @method static \Illuminate\Database\Eloquent\Builder|static has(string $relation, string $operator = '>=', int $count = 1, string $boolean = 'and', \Closure|null $callback = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static doesntHave(string $relation, string $boolean = 'and', \Closure|null $callback = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static orderBy(string|\Illuminate\Database\Query\Expression $column, string $direction = 'asc')
     * @method static \Illuminate\Database\Eloquent\Builder|static orderByDesc(string|\Illuminate\Database\Query\Expression $column)
     * @method static \Illuminate\Database\Eloquent\Builder|static latest(string|\Illuminate\Database\Query\Expression $column = 'created_at')
     * @method static \Illuminate\Database\Eloquent\Builder|static oldest(string|\Illuminate\Database\Query\Expression $column = 'created_at')
     * @method static \Illuminate\Database\Eloquent\Builder|static groupBy(string|array ...$groups)
     * @method static \Illuminate\Database\Eloquent\Builder|static having(string $column, string|null $operator = null, mixed $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static select(string|array ...$columns)
     * @method static \Illuminate\Database\Eloquent\Builder|static selectRaw(string $expression, array $bindings = [])
     * @method static \Illuminate\Database\Eloquent\Builder|static addSelect(array|mixed $column)
     * @method static \Illuminate\Database\Eloquent\Builder|static distinct()
     * @method static \Illuminate\Database\Eloquent\Builder|static join(string $table, string|\Closure $first, string|null $operator = null, string|null $second = null, string $type = 'inner', bool $where = false)
     * @method static \Illuminate\Database\Eloquent\Builder|static leftJoin(string $table, string|\Closure $first, string|null $operator = null, string|null $second = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static limit(int $value)
     * @method static \Illuminate\Database\Eloquent\Builder|static offset(int $value)
     * @method static \Illuminate\Database\Eloquent\Builder|static skip(int $value)
     * @method static \Illuminate\Database\Eloquent\Builder|static take(int $value)
     * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator paginate(int|null|\Closure $perPage = null, array $columns = ['*'], string $pageName = 'page', int|null $page = null)
     * @method static \Illuminate\Contracts\Pagination\Paginator simplePaginate(int|null $perPage = null, array $columns = ['*'], string $pageName = 'page', int|null $page = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static when(mixed $value = null, callable $callback = null, callable|null $default = null)
     * @method static mixed sum(string|\Illuminate\Database\Query\Expression $column)
     * @method static mixed min(string|\Illuminate\Database\Query\Expression $column)
     * @method static mixed max(string|\Illuminate\Database\Query\Expression $column)
     * @method static mixed avg(string|\Illuminate\Database\Query\Expression $column)
     * @method static int update(array $values)
     * @method static mixed delete()
     * @method static int forceDelete()
     * @method static \Illuminate\Database\Eloquent\Builder|static withTrashed(bool $withTrashed = true)
     * @method static \Illuminate\Database\Eloquent\Builder|static onlyTrashed()
     * @method static \Illuminate\Database\Eloquent\Builder|static withoutTrashed()
     * @method static \Illuminate\Database\Query\Builder toBase()
     * @method static \Illuminate\Database\Eloquent\Builder|static whereKey(mixed $id)
     * @method static void chunk(int $count, callable $callback)
     * @method static void chunkById(int $count, callable $callback, string|null $column = null, string|null $alias = null)
     * @method static \Illuminate\Support\LazyCollection lazy(int $chunkSize = 1000)
     * @method static \Illuminate\Database\Eloquent\Collection pluck(string|\Illuminate\Database\Query\Expression $column, string|null $key = null)
     * @method static mixed value(string $column)
     * @method static bool insert(array $values)
     * @method static int insertOrIgnore(array $values)
     * @method static int upsert(array $values, array|string $uniqueBy, array|null $update = null)
     *
     * @see \Illuminate\Database\Eloquent\Builder
     * @see \Illuminate\Database\Query\Builder
     */
    class Model {
        /** @var array */
        protected $attributes = [];
        /** @var array */
        protected $fillable = [];
        /** @var array */
        protected $guarded = ['*'];
        /** @var array */
        protected $hidden = [];
        /** @var array */
        protected $casts = [];
        /** @var array */
        protected $appends = [];
        /** @var string|null */
        protected $table;
        /** @var string */
        protected $primaryKey = 'id';
        /** @var bool */
        public $timestamps = true;
        /** @var bool */
        public $incrementing = true;

        // ── Relationship Methods ──

        /**
         * @param string $related
         * @param string|null $foreignKey
         * @param string|null $ownerKey
         * @param string|null $relation
         * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
         */
        public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null) {}

        /**
         * @param string $related
         * @param string|null $foreignKey
         * @param string|null $localKey
         * @return \Illuminate\Database\Eloquent\Relations\HasOne
         */
        public function hasOne($related, $foreignKey = null, $localKey = null) {}

        /**
         * @param string $related
         * @param string|null $foreignKey
         * @param string|null $localKey
         * @return \Illuminate\Database\Eloquent\Relations\HasMany
         */
        public function hasMany($related, $foreignKey = null, $localKey = null) {}

        /**
         * @param string $related
         * @param string|null $table
         * @param string|null $foreignPivotKey
         * @param string|null $relatedPivotKey
         * @param string|null $parentKey
         * @param string|null $relatedKey
         * @param string|null $relation
         * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
         */
        public function belongsToMany($related, $table = null, $foreignPivotKey = null, $relatedPivotKey = null, $parentKey = null, $relatedKey = null, $relation = null) {}

        /**
         * @param string $related
         * @param string $through
         * @param string|null $firstKey
         * @param string|null $secondKey
         * @param string|null $localKey
         * @param string|null $secondLocalKey
         * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
         */
        public function hasManyThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null) {}

        /**
         * @param string|null $name
         * @param string|null $type
         * @param string|null $id
         * @param string|null $ownerKey
         * @return \Illuminate\Database\Eloquent\Relations\MorphTo
         */
        public function morphTo($name = null, $type = null, $id = null, $ownerKey = null) {}

        /**
         * @param string $related
         * @param string $name
         * @param string|null $type
         * @param string|null $id
         * @param string|null $localKey
         * @return \Illuminate\Database\Eloquent\Relations\MorphOne
         */
        public function morphOne($related, $name, $type = null, $id = null, $localKey = null) {}

        /**
         * @param string $related
         * @param string $name
         * @param string|null $type
         * @param string|null $id
         * @param string|null $localKey
         * @return \Illuminate\Database\Eloquent\Relations\MorphMany
         */
        public function morphMany($related, $name, $type = null, $id = null, $localKey = null) {}

        // ── Attribute Methods ──

        /**
         * @param string|array $attributes
         * @return bool
         */
        public function isDirty($attributes = null) {}

        /**
         * @param string|array $attributes
         * @return bool
         */
        public function isClean($attributes = null) {}

        /**
         * @param string|array $attributes
         * @return bool
         */
        public function wasChanged($attributes = null) {}

        /**
         * @param string $key
         * @param mixed $default
         * @return mixed
         */
        public function getAttribute($key) {}

        /**
         * @param string $key
         * @param mixed $value
         * @return mixed
         */
        public function setAttribute($key, $value) {}

        /**
         * @param string $key
         * @return mixed
         */
        public function getOriginal($key = null, $default = null) {}

        /**
         * @return array
         */
        public function getDirty() {}

        /**
         * @return array
         */
        public function getChanges() {}

        /**
         * @return array
         */
        public function attributesToArray() {}

        /**
         * @return array
         */
        public function toArray() {}

        /**
         * @param int $options
         * @return string
         */
        public function toJson($options = 0) {}

        // ── CRUD Methods ──

        /**
         * @param array $attributes
         * @param array $options
         * @return bool
         */
        public function save(array $options = []) {}

        /**
         * @param array $attributes
         * @param array $options
         * @return bool
         */
        public function update(array $attributes = [], array $options = []) {}

        /**
         * @return bool|null
         */
        public function delete() {}

        /**
         * @return bool|null
         */
        public function forceDelete() {}

        /**
         * @param array $attributes
         * @return static
         */
        public function fill(array $attributes) {}

        /**
         * @param array $attributes
         * @return static
         */
        public function forceFill(array $attributes) {}

        /**
         * @param array $attributes
         * @return static
         */
        public static function create(array $attributes = []) {}

        /**
         * @param array $attributes
         * @param array $values
         * @return static
         */
        public static function firstOrCreate(array $attributes = [], array $values = []) {}

        /**
         * @param array $attributes
         * @param array $values
         * @return static
         */
        public static function updateOrCreate(array $attributes, array $values = []) {}

        /**
         * @param array $attributes
         * @return static
         */
        public static function firstOrNew(array $attributes = [], array $values = []) {}

        /**
         * @param mixed $id
         * @param array $columns
         * @return static|\Illuminate\Database\Eloquent\Collection|null
         */
        public static function find($id, $columns = ['*']) {}

        /**
         * @param mixed $id
         * @param array $columns
         * @return static|\Illuminate\Database\Eloquent\Collection
         * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
         */
        public static function findOrFail($id, $columns = ['*']) {}

        // ── Query Scoping / Events ──

        /**
         * @param string $event
         * @param \Closure|string $callback
         * @return void
         */
        public static function saving($callback) {}

        /**
         * @param \Closure|string $callback
         * @return void
         */
        public static function saved($callback) {}

        /**
         * @param \Closure|string $callback
         * @return void
         */
        public static function creating($callback) {}

        /**
         * @param \Closure|string $callback
         * @return void
         */
        public static function created($callback) {}

        /**
         * @param \Closure|string $callback
         * @return void
         */
        public static function updating($callback) {}

        /**
         * @param \Closure|string $callback
         * @return void
         */
        public static function updated($callback) {}

        /**
         * @param \Closure|string $callback
         * @return void
         */
        public static function deleting($callback) {}

        /**
         * @param \Closure|string $callback
         * @return void
         */
        public static function deleted($callback) {}

        /**
         * @param \Closure|string $callback
         * @return void
         */
        public static function booting($callback) {}

        /**
         * @param \Closure|string $callback
         * @return void
         */
        protected static function booted() {}

        // ── Misc ──

        /**
         * @param string|array $relations
         * @return static
         */
        public function load($relations) {}

        /**
         * @param string|array $relations
         * @return static
         */
        public function loadMissing($relations) {}

        /**
         * @param string $relation
         * @return bool
         */
        public function relationLoaded($relation) {}

        /**
         * @param string $relation
         * @return mixed
         */
        public function getRelation($relation) {}

        /**
         * @param string $relation
         * @param mixed $value
         * @return static
         */
        public function setRelation($relation, $value) {}

        /**
         * @return string
         */
        public function getTable() {}

        /**
         * @return string
         */
        public function getKeyName() {}

        /**
         * @return mixed
         */
        public function getKey() {}

        /**
         * @return string
         */
        public function getRouteKeyName() {}

        /**
         * @return \Illuminate\Database\Eloquent\Builder
         */
        public static function query() {}

        /**
         * @return \Illuminate\Database\Eloquent\Builder
         */
        public function newQuery() {}

        /**
         * @return \Illuminate\Database\Eloquent\Builder
         */
        public function newModelQuery() {}

        /**
         * @return static
         */
        public function replicate(?array $except = null) {}

        /**
         * @return static
         */
        public function fresh($with = []) {}

        /**
         * @return static
         */
        public function refresh() {}

        /**
         * @return bool
         */
        public function exists() {}

        /**
         * @param string $scope
         * @param array $parameters
         * @return \Illuminate\Database\Eloquent\Builder
         */
        public static function __callStatic($method, $parameters) {}
    }

    /**
     * @method static \Illuminate\Database\Eloquent\Builder|static where(string|\Closure|array $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static orWhere(string|\Closure|array $column, mixed $operator = null, mixed $value = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereNotIn(string $column, mixed $values, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereNull(string|array $columns, string $boolean = 'and', bool $not = false)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereNotNull(string|array $columns, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereBetween(string $column, iterable $values, string $boolean = 'and', bool $not = false)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereDate(string $column, string $operator, mixed $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereMonth(string $column, string $operator, mixed $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereYear(string $column, string $operator, mixed $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereDay(string $column, string $operator, mixed $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereTime(string $column, string $operator, mixed $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereRaw(string $sql, mixed $bindings = [], string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static whereHas(string $relation, \Closure|null $callback = null, string $operator = '>=', int $count = 1)
     * @method static \Illuminate\Database\Eloquent\Builder|static whereDoesntHave(string $relation, \Closure|null $callback = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static with(string|array $relations, string|\Closure|null $callback = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static withCount(string|array $relations)
     * @method static \Illuminate\Database\Eloquent\Builder|static has(string $relation, string $operator = '>=', int $count = 1, string $boolean = 'and', \Closure|null $callback = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static doesntHave(string $relation, string $boolean = 'and', \Closure|null $callback = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static orderBy(string $column, string $direction = 'asc')
     * @method static \Illuminate\Database\Eloquent\Builder|static orderByDesc(string $column)
     * @method static \Illuminate\Database\Eloquent\Builder|static latest(string $column = 'created_at')
     * @method static \Illuminate\Database\Eloquent\Builder|static oldest(string $column = 'created_at')
     * @method static \Illuminate\Database\Eloquent\Builder|static groupBy(string|array ...$groups)
     * @method static \Illuminate\Database\Eloquent\Builder|static having(string $column, string|null $operator = null, mixed $value = null, string $boolean = 'and')
     * @method static \Illuminate\Database\Eloquent\Builder|static select(string|array ...$columns)
     * @method static \Illuminate\Database\Eloquent\Builder|static selectRaw(string $expression, array $bindings = [])
     * @method static \Illuminate\Database\Eloquent\Builder|static addSelect(array|mixed $column)
     * @method static \Illuminate\Database\Eloquent\Builder|static distinct()
     * @method static \Illuminate\Database\Eloquent\Builder|static join(string $table, string|\Closure $first, string|null $operator = null, string|null $second = null, string $type = 'inner', bool $where = false)
     * @method static \Illuminate\Database\Eloquent\Builder|static leftJoin(string $table, string|\Closure $first, string|null $operator = null, string|null $second = null)
     * @method static \Illuminate\Database\Eloquent\Builder|static limit(int $value)
     * @method static \Illuminate\Database\Eloquent\Builder|static offset(int $value)
     * @method static \Illuminate\Database\Eloquent\Builder|static skip(int $value)
     * @method static \Illuminate\Database\Eloquent\Builder|static take(int $value)
     * @method static \Illuminate\Database\Eloquent\Builder|static when(mixed $value = null, callable $callback = null, callable|null $default = null)
     * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator paginate(int|null|\Closure $perPage = null, array $columns = ['*'], string $pageName = 'page', int|null $page = null)
     * @method static \Illuminate\Contracts\Pagination\Paginator simplePaginate(int|null $perPage = null, array $columns = ['*'], string $pageName = 'page', int|null $page = null)
     * @method static mixed sum(string $column)
     * @method static mixed min(string $column)
     * @method static mixed max(string $column)
     * @method static mixed avg(string $column)
     * @method static int update(array $values)
     * @method static mixed delete()
     * @method static \Illuminate\Database\Eloquent\Builder|static withTrashed(bool $withTrashed = true)
     * @method static \Illuminate\Database\Eloquent\Builder|static onlyTrashed()
     * @method static \Illuminate\Database\Eloquent\Collection pluck(string $column, string|null $key = null)
     * @method static mixed value(string $column)
     * @method static void chunk(int $count, callable $callback)
     * @method static void chunkById(int $count, callable $callback, string|null $column = null, string|null $alias = null)
     * @method static bool insert(array $values)
     * @method static int upsert(array $values, array|string $uniqueBy, array|null $update = null)
     * @method static static|null find(mixed $id, array $columns = ['*'])
     * @method static static findOrFail(mixed $id, array $columns = ['*'])
     * @method static static|null first(array $columns = ['*'])
     * @method static static firstOrFail(array $columns = ['*'])
     * @method static static create(array $attributes = [])
     * @method static static firstOrCreate(array $attributes = [], array $values = [])
     * @method static static updateOrCreate(array $attributes, array $values = [])
     * @method static \Illuminate\Database\Eloquent\Collection|static[] get(array $columns = ['*'])
     * @method static int count(string $columns = '*')
     * @method static bool exists()
     * @method static bool doesntExist()
     *
     * @see \Illuminate\Database\Eloquent\Builder
     */
    class Builder {}
}

namespace Illuminate\Foundation\Auth {

    /**
     * @property int $id
     * @property string $name
     * @property string $email
     * @property string|null $email_verified_at
     * @property string $password
     * @property string|null $remember_token
     * @property \Illuminate\Support\Carbon|null $created_at
     * @property \Illuminate\Support\Carbon|null $updated_at
     *
     * @mixin \Illuminate\Database\Eloquent\Model
     */
    class User extends \Illuminate\Database\Eloquent\Model implements \Illuminate\Contracts\Auth\Authenticatable, \Illuminate\Contracts\Auth\CanResetPassword {
        use \Illuminate\Notifications\Notifiable;
        public function getAuthIdentifierName() { return 'id'; }
        public function getAuthIdentifier() { return $this->getKey(); }
        public function getAuthPassword() { return $this->password; }
        public function getAuthPasswordName() { return 'password'; }
        public function getRememberToken() { return $this->remember_token; }
        public function setRememberToken($value) {}
        public function getRememberTokenName() { return 'remember_token'; }
        public function getEmailForPasswordReset() { return $this->email; }
        public function sendPasswordResetNotification($token) {}
    }
}

namespace Illuminate\Http {

    /**
     * @method \Illuminate\Contracts\Auth\Authenticatable|null user(string|null $guard = null)
     * @method bool routeIs(string ...$patterns)
     * @method string|null ip()
     * @method string getRequestUri()
     * @method string fullUrl()
     * @method array all(array|mixed|null $keys = null)
     * @method mixed input(string|null $key = null, mixed $default = null)
     * @method bool has(string|array $key)
     * @method bool filled(string|array $key)
     * @method string|null header(string|null $key = null, string|array|null $default = null)
     * @method mixed query(string|null $key = null, mixed $default = null)
     * @method bool hasFile(string $key)
     * @method \Illuminate\Http\UploadedFile|array|null file(string|null $key = null, mixed $default = null)
     * @method mixed validate(array $rules, array $messages = [], array $attributes = [])
     * @method bool expectsJson()
     * @method bool ajax()
     * @method bool wantsJson()
     * @property mixed $leave_dates
     * @property mixed $reason
     * @property mixed $leave_type
     * @property mixed $start_date
     * @property mixed $end_date
     */
    class Request {
        /**
         * @param array $input
         * @return static
         */
        public function merge(array $input) {}

        /**
         * @param string $key
         * @return mixed
         */
        public function __get($key) {}

        /**
         * @param array|mixed $keys
         * @return array
         */
        public function only($keys) {}

        /**
         * @param array $rules
         * @param array $messages
         * @param array $customAttributes
         * @return array
         */
        public function validate(array $rules = [], array $messages = [], array $customAttributes = []) {}
    }

    class RedirectResponse {
        /**
         * @param string|array $key
         * @param mixed $value
         * @return static
         */
        public function with($key, $value = null) {}

        /**
         * @param string|array $key
         * @param mixed $value
         * @return static
         */
        public function withInput($key = null) {}

        /**
         * @param \Illuminate\Contracts\Support\MessageProvider|array|string $provider
         * @param string $key
         * @return static
         */
        public function withErrors($provider, $key = 'default') {}

        /**
         * @param string $fragment
         * @return static
         */
        public function withFragment($fragment) {}
    }
    class Response {
        /**
         * @param string $key
         * @param string|array $values
         * @param bool $replace
         * @return static
         */
        public function header($key, $values, $replace = true) {}
    }
    class JsonResponse {}
    class ResponseFactory {
        /**
         * @param mixed $data
         * @param int $status
         * @param array $headers
         * @param int $options
         * @return \Illuminate\Http\JsonResponse
         */
        public function json($data = [], $status = 200, array $headers = [], $options = 0) {}

        /**
         * @param string $content
         * @param int $status
         * @param array $headers
         * @return \Illuminate\Http\Response
         */
        public function make($content = '', $status = 200, array $headers = []) {}

        /**
         * @param \Illuminate\Contracts\View\View|string $view
         * @param array $data
         * @param int $status
         * @param array $headers
         * @return \Illuminate\Http\Response
         */
        public function view($view, $data = [], $status = 200, array $headers = []) {}

        /**
         * @param \SplFileInfo|string $file
         * @param string|null $name
         * @param array $headers
         * @param string|null $disposition
         * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
         */
        public function download($file, $name = null, array $headers = [], $disposition = 'attachment') {}

        /**
         * @param \SplFileInfo|string $file
         * @param string|null $name
         * @param array $headers
         * @param string|null $disposition
         * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
         */
        public function file($file, array $headers = []) {}

        /**
         * @param string $path
         * @param int $status
         * @param array $headers
         * @param bool|null $secure
         * @return \Illuminate\Http\RedirectResponse
         */
        public function redirectTo($path, $status = 302, $headers = [], $secure = null) {}

        /**
         * @return \Symfony\Component\HttpFoundation\StreamedResponse
         */
        public function stream($callback, $status = 200, array $headers = []) {}

        /**
         * @return \Symfony\Component\HttpFoundation\StreamedResponse
         */
        public function streamDownload($callback, $name = null, array $headers = [], $disposition = 'attachment') {}

        /**
         * @return \Illuminate\Http\Response
         */
        public function noContent($status = 204, array $headers = []) {}
    }
    class UploadedFile {}
}

namespace Illuminate\Routing {
    class Redirector {
        /**
         * @return \Illuminate\Http\RedirectResponse
         */
        public function back($status = 302, $headers = [], $fallback = false) {}

        /**
         * @param string $path
         * @param int $status
         * @param array $headers
         * @param bool|null $secure
         * @return \Illuminate\Http\RedirectResponse
         */
        public function to($path, $status = 302, $headers = [], $secure = null) {}

        /**
         * @param string $route
         * @param mixed $parameters
         * @param int $status
         * @param array $headers
         * @return \Illuminate\Http\RedirectResponse
         */
        public function route($route, $parameters = [], $status = 302, $headers = []) {}

        /**
         * @param string $action
         * @param mixed $parameters
         * @param int $status
         * @param array $headers
         * @return \Illuminate\Http\RedirectResponse
         */
        public function action($action, $parameters = [], $status = 302, $headers = []) {}

        /**
         * @param string|null $path
         * @param int $status
         * @param array $headers
         * @return \Illuminate\Http\RedirectResponse
         */
        public function away($path, $status = 302, $headers = []) {}

        /**
         * @return \Illuminate\Http\RedirectResponse
         */
        public function refresh($status = 302, $headers = []) {}

        /**
         * @return \Illuminate\Http\RedirectResponse
         */
        public function intended($default = '/', $status = 302, $headers = [], $secure = null) {}

        /**
         * @return \Illuminate\Http\RedirectResponse
         */
        public function guest($path, $status = 302, $headers = [], $secure = null) {}
    }
    class Controller {
        /**
         * @param string $ability
         * @param mixed $arguments
         * @return \Illuminate\Auth\Access\Response
         */
        public function authorize($ability, $arguments = []) {}
    }
}

namespace Illuminate\Validation {
    class Rule {
        /**
         * @param string $table
         * @param string $column
         * @return \Illuminate\Validation\Rules\Unique
         */
        public static function unique(string $table, string $column = 'NULL')
        {
            return new \Illuminate\Validation\Rules\Unique();
        }
        public static function exists(string $table, string $column = 'NULL') {}
        public static function in(array|string $values) {}
        public static function notIn(array|string $values) {}
        public static function requiredIf($callback) {}
        public static function excludeIf($callback) {}
        public static function when($condition, $rules, $defaultRules = []) {}
        public static function dimensions(array $constraints = []) {}
    }
    class ValidationException extends \Exception {}
}

namespace Illuminate\Validation\Rules {
    class Unique {
        /**
         * @param mixed $id
         * @param string $idColumn
         * @return $this
         */
        public function ignore($id, $idColumn = 'id'): self { return $this; }

        /** @return string */
        public function __toString(): string { return ''; }
    }
}

namespace Illuminate\Auth {
    class AuthManager {}
}

namespace Illuminate\Auth\Access {
    class Response {}
    class AuthorizationException extends \Exception {}
}

namespace Illuminate\Session {
    class SessionManager {}
}

namespace Illuminate\Cache {
    class CacheManager {}
}

namespace Illuminate\Config {
    class Repository {}
}

namespace Illuminate\Database {
    class ConnectionInterface {}

    class Connection {
        /** @return \Illuminate\Database\Query\Builder */
        public function table($table, $as = null) {}
    }
}

namespace Illuminate\Database\Query {
    class Builder {
        public function where($column, $operator = null, $value = null, $boolean = 'and') {}
        public function orWhere($column, $operator = null, $value = null) {}
        public function whereIn($column, $values, $boolean = 'and', $not = false) {}
        public function select($columns = ['*']) {}
        public function join($table, $first, $operator = null, $second = null, $type = 'inner', $where = false) {}
        public function leftJoin($table, $first, $operator = null, $second = null) {}
        public function groupBy(...$groups) {}
        public function orderBy($column, $direction = 'asc') {}
        public function limit($value) {}
        public function offset($value) {}
        public function get($columns = ['*']) {}
        public function first($columns = ['*']) {}
        public function pluck($column, $key = null) {}
        public function count($columns = '*') {}
        public function sum($column) {}
        public function avg($column) {}
        public function max($column) {}
        public function min($column) {}
        public function exists() {}
        public function doesntExist() {}
        public function insert(array $values) {}
        public function update(array $values) {}
        public function delete($id = null) {}
        public function whereRaw($sql, $bindings = [], $boolean = 'and') {}
        public function selectRaw($expression, $bindings = []) {}
        public function whereNull($columns, $boolean = 'and', $not = false) {}
        public function whereNotNull($columns, $boolean = 'and') {}
        public function whereMonth($column, $operator, $value = null, $boolean = 'and') {}
        public function whereYear($column, $operator, $value = null, $boolean = 'and') {}
        public function whereDate($column, $operator, $value = null, $boolean = 'and') {}
        public function whereBetween($column, iterable $values, $boolean = 'and', $not = false) {}
        public function having($column, $operator = null, $value = null, $boolean = 'and') {}
        public function latest($column = 'created_at') {}
        public function oldest($column = 'created_at') {}
        public function distinct() {}
        public function chunk($count, callable $callback) {}
        public function value($column) {}
        public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null) {}
    }

    class Expression {}
}

namespace Illuminate\Broadcasting {
    class BroadcastManager {}
}

namespace Illuminate\Foundation\Bus {
    class PendingDispatch {}
}

namespace Illuminate\Database\Eloquent {
    class ModelNotFoundException extends \RuntimeException {}

    trait SoftDeletes {
        public static function withTrashed($withTrashed = true) {}
        public static function onlyTrashed() {}
        public static function withoutTrashed() {}
        public function trashed() {}
        public function restore() {}
        public function forceDelete() {}
    }
}

namespace Symfony\Component\HttpFoundation {
    class Cookie {}
    class StreamedResponse {}
    class BinaryFileResponse {}
}

namespace Symfony\Component\HttpKernel\Exception {
    class HttpException extends \RuntimeException {}
    class NotFoundHttpException extends HttpException {}
}

namespace {
    /**
     * Intelephense stubs for common Laravel global helper functions.
     * These are defined in Illuminate\Foundation\helpers.php and
     * Illuminate\Support\helpers.php at runtime.
     */

    /**
     * @param  string|null  $view
     * @param  \Illuminate\Contracts\Support\Arrayable|array  $data
     * @param  array  $mergeData
     * @return ($view is null ? \Illuminate\Contracts\View\Factory : \Illuminate\Contracts\View\View)
     */
    function view(?string $view = null, $data = [], $mergeData = []) {}

    /**
     * @param  \Illuminate\Contracts\View\View|string|array|null  $content
     * @param  int  $status
     * @param  array  $headers
     * @return \Illuminate\Http\Response|\Illuminate\Http\ResponseFactory
     */
    function response($content = null, $status = 200, array $headers = []) {}

    /**
     * @param  string|null  $to
     * @param  int  $status
     * @param  array  $headers
     * @param  bool|null  $secure
     * @return ($to is null ? \Illuminate\Routing\Redirector : \Illuminate\Http\RedirectResponse)
     */
    function redirect($to = null, $status = 302, $headers = [], $secure = null) {}

    /**
     * @param  string|null  $key
     * @param  mixed  ...$parameters
     * @return ($key is null ? \Illuminate\Contracts\Translation\Translator : string)
     */
    function trans($key = null, ...$parameters) {}

    /**
     * @param  string  $key
     * @param  array  $replace
     * @param  string|null  $locale
     * @return string
     */
    function __($key, $replace = [], $locale = null) {}

    /**
     * @param  string|null  $name
     * @param  mixed  $parameters
     * @param  bool  $absolute
     * @return string
     */
    function route($name = null, $parameters = [], $absolute = true) {}

    /**
     * @param  string  $path
     * @param  mixed  $parameters
     * @param  bool|null  $secure
     * @return string
     */
    function url($path = '', $parameters = [], $secure = null) {}

    /**
     * @param  string  $path
     * @return string
     */
    function asset($path, $secure = null) {}

    /**
     * @param  int  $code
     * @param  string  $message
     * @param  array  $headers
     * @return never
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    function abort($code, $message = '', array $headers = []) {}

    /**
     * @param  bool  $boolean
     * @param  int  $code
     * @param  string  $message
     * @param  array  $headers
     * @return void
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    function abort_if($boolean, $code, $message = '', array $headers = []) {}

    /**
     * @param  bool  $boolean
     * @param  int  $code
     * @param  string  $message
     * @param  array  $headers
     * @return void
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    function abort_unless($boolean, $code, $message = '', array $headers = []) {}

    /**
     * @param  string|null  $abstract
     * @param  array  $parameters
     * @return ($abstract is null ? \Illuminate\Contracts\Foundation\Application : mixed)
     */
    function app($abstract = null, array $parameters = []) {}

    /**
     * @param  string|null  $guard
     * @return ($guard is null ? \Illuminate\Contracts\Auth\Factory : \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard)
     */
    function auth($guard = null) {}

    /**
     * @param  string|null  $path
     * @return string
     */
    function base_path($path = '') {}

    /**
     * @param  string|null  $path
     * @return string
     */
    function storage_path($path = '') {}

    /**
     * @param  string|null  $path
     * @return string
     */
    function public_path($path = '') {}

    /**
     * @param  string|null  $path
     * @return string
     */
    function resource_path($path = '') {}

    /**
     * @param  string|null  $path
     * @return string
     */
    function app_path($path = '') {}

    /**
     * @param  string|null  $path
     * @return string
     */
    function database_path($path = '') {}

    /**
     * @param  string|null  $key
     * @param  mixed  $default
     * @return ($key is null ? \Illuminate\Config\Repository : mixed)
     */
    function config($key = null, $default = null) {}

    /**
     * @param  mixed  $value
     * @return \Illuminate\Support\Collection
     */
    function collect($value = []) {}

    /**
     * @return \Illuminate\Support\Carbon
     */
    function now() {}

    /**
     * @return \Illuminate\Support\Carbon
     */
    function today() {}

    /**
     * @param  string|null  $key
     * @param  mixed  $default
     * @return ($key is null ? \Illuminate\Session\SessionManager : mixed)
     */
    function session($key = null, $default = null) {}

    /**
     * @param  string|null  $key
     * @param  mixed  $value
     * @return ($key is null ? \Illuminate\Cache\CacheManager : mixed)
     */
    function cache($key = null, $value = null, $ttl = null) {}

    /**
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    function old($key = null, $default = null) {}

    /**
     * @param  mixed|null  $value
     * @param  callable|null  $callback
     * @return mixed
     */
    function optional($value = null, ?callable $callback = null) {}

    /**
     * @param  string  $value
     * @param  string  $function
     * @return string
     */
    function e($value, $doubleEncode = true) {}

    /**
     * @param  string  $key
     * @return bool
     */
    function filled($key) {}

    /**
     * @param  string  $key
     * @return bool
     */
    function blank($key) {}

    /**
     * @param  mixed  ...$values
     * @return void
     */
    function dd(...$values) {}

    /**
     * @param  mixed  ...$values
     * @return void
     */
    function dump(...$values) {}

    /**
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    function info($message, $context = []) {}

    /**
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    function logger($message = null, array $context = []) {}

    /**
     * @param  string  $key
     * @param  array  $replace
     * @param  string|null  $locale
     * @return string|array|null
     */
    function trans_choice($key, $number, array $replace = [], $locale = null) {}

    /**
     * @param  string  $ability
     * @param  mixed  ...$arguments
     * @return \Illuminate\Auth\Access\Response
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    function authorize($ability, ...$arguments) {}

    /**
     * @param  object|string  $job
     * @return \Illuminate\Foundation\Bus\PendingDispatch
     */
    function dispatch($job) {}

    /**
     * @param  string  $event
     * @param  mixed  $payload
     * @param  bool  $halt
     * @return array|null
     */
    function event($event, $payload = [], $halt = false) {}

    /**
     * @param  string  $path
     * @param  bool  $secure
     * @return string
     */
    function mix($path, $manifestDirectory = '') {}

    /**
     * @param  mixed  $value
     * @param  callable  $callback
     * @return mixed
     */
    function tap($value, $callback = null) {}

    /**
     * @param  mixed  $value
     * @param  callable|null  $callback
     * @return mixed
     */
    function value($value, ...$args) {}

    /**
     * @param  mixed  $condition
     * @param  mixed  $value
     * @param  mixed  $default
     * @return mixed
     */
    function when($condition, $value, $default = null) {}

    /**
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    function env($key, $default = null) {}

    /**
     * @param  string|null  $name
     * @param  mixed  $parameters
     * @return string
     */
    function action($name = null, $parameters = []) {}

    /**
     * @param  \Illuminate\Contracts\Validation\Factory|null  $factory
     * @return \Illuminate\Contracts\Validation\Validator
     */
    function validator(array $data = [], array $rules = [], array $messages = [], array $attributes = []) {}

    /**
     * @param  \BackedEnum|\Illuminate\Contracts\Support\DeferringDisplayableValue|\Illuminate\Contracts\Support\Htmlable|string|int|float|null  $value
     * @return \Illuminate\Support\HtmlString
     */
    function resolve($name, array $parameters = []) {}

    /**
     * @param  mixed  $value
     * @param  mixed  ...$args
     * @return mixed
     */
    function with($value, ?callable $callback = null) {}

    /**
     * @param  string  $path
     * @param  string|null  $locale
     * @return \Illuminate\Contracts\Translation\Translator|string
     */
    function lang($key = null, $replace = [], $locale = null) {}

    /**
     * @param  string  $path
     * @param  bool|null  $secure
     * @return string
     */
    function secure_url($path, $parameters = []) {}

    /**
     * @param  string  $path
     * @param  bool|null  $secure
     * @return string
     */
    function secure_asset($path) {}

    /**
     * @param  string|null  $connection
     * @return \Illuminate\Database\ConnectionInterface
     */
    function db($connection = null) {}

    /**
     * @param  string|null  $key
     * @param  mixed  $default
     * @return \Illuminate\Http\Request|mixed
     */
    function request($key = null, $default = null) {}

    /**
     * @param  string  $expression
     * @return \Illuminate\Database\Query\Expression
     */
    function raw($expression) {}

    /**
     * @param  bool  $boolean
     * @param  \Closure  $callback
     * @param  \Closure|null  $default
     * @return mixed
     */
    function throw_if($boolean, $exception, ...$parameters) {}

    /**
     * @param  bool  $boolean
     * @param  \Closure  $callback
     * @param  \Closure|null  $default
     * @return mixed
     */
    function throw_unless($boolean, $exception, ...$parameters) {}

    /**
     * @param  string  $path
     * @param  string  $disk
     * @return bool
     */
    function file_exists_on_disk(string $path, string $disk = 'local'): bool { return true; }

    /**
     * @param  mixed  ...$args
     * @return \Illuminate\Contracts\Broadcasting\Factory|\Illuminate\Broadcasting\BroadcastManager
     */
    function broadcast($args = null) {}

    /**
     * @param  string|null  $name
     * @return \Illuminate\Contracts\Cookie\Factory|\Symfony\Component\HttpFoundation\Cookie
     */
    function cookie($name = null, $value = null, $minutes = 0, $path = null, $domain = null, $secure = null, $httpOnly = true, $raw = false, $sameSite = null) {}

    /**
     * @param  string  $path
     * @return string
     */
    function config_path($path = '') {}

    /**
     * @param  string  $expression
     * @param  mixed  ...$args
     * @return string
     */
    function csrf_token() {}

    /**
     * @return string
     */
    function csrf_field() {}

    /**
     * @param  string  $method
     * @return string
     */
    function method_field($method) {}

    /**
     * @param  bool  $expression
     * @param  string  $message
     * @return void
     */
    function report($exception) {}

    /**
     * @param int $status
     * @param array $headers
     * @param string|null $fallback
     * @return \Illuminate\Http\RedirectResponse
     */
    function back($status = 302, $headers = [], $fallback = false) {}
}

namespace PhpOffice\PhpSpreadsheet\Style {
    class Alignment {
        const HORIZONTAL_CENTER = 'center';
        const HORIZONTAL_LEFT = 'left';
        const HORIZONTAL_RIGHT = 'right';
        const VERTICAL_CENTER = 'center';
        const VERTICAL_TOP = 'top';
        const VERTICAL_BOTTOM = 'bottom';
        /** @return static */
        public function setHorizontal($horizontal) {}
        /** @return static */
        public function setVertical($vertical) {}
        /** @return static */
        public function setWrapText($wrapText) {}
    }
    class Protection {
        const PROTECTION_PROTECTED = 'protected';
        const PROTECTION_UNPROTECTED = 'unprotected';
    }
    class Style {
        /** @return Alignment */
        public function getAlignment() {}
        public function applyFromArray(array $styleArray) {}
    }
}

namespace PhpOffice\PhpSpreadsheet\Cell {
    class Cell {
        public function getValue() {}
    }
}

namespace Maatwebsite\Excel\Concerns {
    interface FromArray {}
    interface WithHeadings {}
    interface WithStyles {}
    interface WithEvents {}
}

namespace Maatwebsite\Excel\Events {
    class AfterSheet {
        /** @var \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet */
        public $sheet;
    }
}

namespace Illuminate\Mail {
    class Mailable {
        /** @return string */
        public function render() {}
    }
}

namespace Illuminate\Support\Facades {
    class Validator {
        /**
         * @param array $data
         * @param array $rules
         * @param array $messages
         * @param array $customAttributes
         * @return \Illuminate\Validation\Validator
         */
        public static function make(array $data, array $rules, array $messages = [], array $customAttributes = []) {}
    }
}

namespace PhpOffice\PhpSpreadsheet {
    class IOFactory {
        /**
         * @param string $filename
         * @return Spreadsheet
         */
        public static function load($filename) {}
        /**
         * @param Spreadsheet $spreadsheet
         * @param string $writerType
         * @return Writer\IWriter
         */
        public static function createWriter(Spreadsheet $spreadsheet, $writerType) {}
    }
    class Spreadsheet {
        /** @return Worksheet\Worksheet|null */
        public function getSheetByName($name) {}
        /** @return Worksheet\Worksheet */
        public function getActiveSheet() {}
        /** @return Worksheet\Worksheet[] */
        public function getAllSheets() {}
        /** @return array */
        public function getDefinedNames() {}
        public function removeDefinedName($name, $scope = null) {}
        /** @return int */
        public function getSheetCount() {}
        /** @return Worksheet\Worksheet */
        public function getSheet($sheetIndex) {}
        public function removeSheetByIndex($sheetIndex) {}
    }
}

namespace PhpOffice\PhpSpreadsheet\Worksheet {
    class Worksheet {
        public function setCellValue($cell, $value) {}
        /** @return \PhpOffice\PhpSpreadsheet\Style\Style */
        public function getStyle($cellCoordinate) {}
        /** @return array */
        public function getMergeCells() {}
        public function unmergeCells($range) {}
        public function mergeCells($range) {}
        /** @return \PhpOffice\PhpSpreadsheet\Cell\Cell */
        public function getCell($cellCoordinate) {}
        /** @return string */
        public function getTitle() {}
    }
}

namespace PhpOffice\PhpSpreadsheet\Cell {
    class Coordinate {
        public static function columnIndexFromString($columnAddress) {}
        public static function stringFromColumnIndex($columnIndex) {}
    }
}

namespace PhpOffice\PhpSpreadsheet\Writer {
    interface IWriter {
        public function save($filename);
    }
}

namespace setasign\Fpdi {
    class Fpdi extends \FPDF {
        public function setSourceFile($filename) {}
        public function importPage($pageNumber, $box = '/CropBox') {}
        public function useImportedPage($tplIdx, $x = 0, $y = 0, $width = null, $height = null) {}
        public function useTemplate($tplIdx, $x = 0, $y = 0, $width = null, $height = null) {}
    }
}

namespace {
    class FPDF {
        public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4') {}
        public function AddPage($orientation = '', $size = '', $rotation = 0) {}
        public function SetFont($family, $style = '', $size = 0) {}
        public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '') {}
        public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false) {}
        public function Text($x, $y, $txt) {}
        public function Image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '') {}
        public function Output($dest = '', $name = '', $isUTF8 = false) {}
        public function Write($h, $txt, $link = '') {}
        public function SetXY($x, $y) {}
        public function SetX($x) {}
        public function SetY($y) {}
        public function GetX() {}
        public function GetY() {}
        public function Ln($h = null) {}
        public function AliasNbPages($alias = '{nb}') {}
        public function SetMargins($left, $top, $right = null) {}
    }
}
