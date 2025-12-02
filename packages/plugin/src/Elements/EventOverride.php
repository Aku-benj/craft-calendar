<?php

namespace Solspace\Calendar\Elements;

use Carbon\Carbon;
use craft\base\Element;
use craft\base\Field;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\errors\SiteNotFoundException;
use craft\events\RegisterElementActionsEvent;
use craft\fieldlayoutelements\TitleField;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Illuminate\Support\Collection;
use Solspace\Calendar\Calendar;
use Solspace\Calendar\Elements\conditions\EventCondition;
use Solspace\Calendar\Elements\Db\EventQuery;
use Solspace\Calendar\Library\Duration\EventDuration;
use Solspace\Calendar\Library\Helpers\DateHelper;
use Solspace\Calendar\Library\Helpers\PermissionHelper;
use Solspace\Calendar\Models\CalendarModel;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use yii\base\Event as BaseEvent;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class EventOverride extends Element
{
    public const TABLE_STD = 'calendar_event_overrides';
    public const TABLE = '{{%' . self::TABLE_STD . '}}';

    public const EVENT_TRANSFORM_JSON_VALUE = 'transform-json-value';

    public ?int $eventId = null;
    public ?Event $event = null;

    public null|Carbon|\DateTime $date = null;

    // Overridable fields
    public null|Carbon|\DateTime $startTime = null;
    public null|Carbon|\DateTime $startTimeLocalized = null;

    public null|Carbon|\DateTime $endTime = null;
    public null|Carbon|\DateTime $endTimeLocalized = null;

    public ?bool $allDay = null;

    public ?string $name = null;

    /**
     * Event Override constructor.
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $event = Calendar::getInstance()->events->getEventById($this->eventId);
        if (!$event) {
            return false;
        }
        $this->event = $event;

        $date = $this->date;
        if (empty($date) || !($date instanceof \DateTime)) {
            return false;
        }

        $startTime = $this->startTime ?? $event->startDate;
        if ($startTime instanceof \DateTime) {
            // Extract Date from $date and time from $startTime 
            $startTime = $date->format('Y-m-d ') . $startTime->format('H:i:s');
        }

        $endTime = $this->endTime ?? $event->endDate;
        if ($endTime instanceof \DateTime) {
            // Extract Date from $date and time from $endTime
            $endTime = $date->format('Y-m-d ') . $endTime->format('H:i:s');
        }

        $this->startTime = new Carbon($startTime, DateHelper::UTC);
        $this->startTimeLocalized = new Carbon($startTime);
        $this->endTime = new Carbon($endTime, DateHelper::UTC);
        $this->endTimeLocalized = new Carbon($endTime);
    }

    public static function tableName(): string
    {
        return self::TABLE;
    }

    public static function displayName(): string
    {
        return \Craft::t('app', 'Event Override');
    }

    public static function lowerDisplayName(): string
    {
        return \Craft::t('app', 'event override');
    }

    public static function refHandle(): string
    {
        return 'event_override';
    }

    public static function trackChanges(): bool
    {
        return false;
    }

    public function getIsTitleTranslatable(): bool
    {
        return Field::TRANSLATION_METHOD_NONE !== $this->event->getCalendar()->titleTranslationMethod;
    }

    public function getTitleTranslationDescription(): ?string
    {
        return ElementHelper::translationDescription(
            $this->event->getCalendar()->titleTranslationMethod
        );
    }

    public function getTitleTranslationKey(): string
    {
        $calendar = $this->event->getCalendar();

        return ElementHelper::translationKey(
            $this,
            $calendar->titleTranslationMethod,
            $calendar->titleTranslationKeyFormat
        );
    }

    /**
     * Updates the entry's title, if its entry type has a dynamic title format.
     */
    public function updateTitle(): void
    {
        $calendar = $this->event->getCalendar();

        if (!$calendar->hasTitleField) {
            // Make sure that the locale has been loaded in case the title format has any Date/Time fields
            \Craft::$app->getLocale();

            // Set Craft to the entry's site's language, in case the title format has any static translations
            $language = \Craft::$app->language;

            \Craft::$app->language = $this->getSite()->language;

            $title = \Craft::$app->getView()->renderObjectTemplate($calendar->titleFormat, $this);

            if ('' !== $title) {
                $this->title = $title;
            }

            \Craft::$app->language = $language;
        }
    }

    /**
     * @return ElementQueryInterface|EventQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new EventQuery(self::class);
    }

    public static function createCondition(): ElementConditionInterface
    {
        return \Craft::createObject(EventCondition::class, [static::class]);
    }

    public static function typeHandle(): string
    {
        return 'event_override';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasContent(): bool
    {
        return version_compare(\Craft::$app->getVersion(), '5.0.0', '<');
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return false;
    }

    public static function buildQuery(?array $config = null): ElementQueryInterface
    {
        $query = self::find();

        if (null !== $config) {
            $propertyAccessor = new PropertyAccessor();

            foreach ($config as $key => $value) {
                if ($propertyAccessor->isWritable($query, $key)) {
                    $propertyAccessor->setValue($query, $key, $value);
                }
            }
        }

        $query->setOverlapThreshold(Calendar::getInstance()->settings->getOverlapThreshold());
        $query->siteId ??= \Craft::$app->sites->currentSite->id;

        return $query;
    }

    public static function create(?int $siteId = null, int $eventId): self|null
    {
        $event = Calendar::getInstance()->events->getEventById($eventId, $siteId);
        if (!$event) {
            return null;
        }

        $element = new self();
        $element->allDay = $event->allDay;
        $element->date = $event->startDate;
        $element->startTime = $event->startDate;
        $element->endDate = $event->endDate;
        $element->eventId = $eventId;

        $element->enabled = true;

        if ($siteId) {
            $element->siteId = $siteId;

            $siteSettings = $event->getCalendar()->getSiteSettingsForSite($siteId);
            if ($siteSettings) {
                $element->enabledForSite = $siteSettings->enabledByDefault;
            }
        }

        return $element;
    }

    /**
     * @throws SiteNotFoundException
     */
    public function getSupportedSites(): array
    {
        if (static::isLocalized()) {
            $siteSettings = $this->event->getCalendar()->getSiteSettings();

            $supportedSites = [];
            foreach ($siteSettings as $site) {
                $supportedSites[] = [
                    'siteId' => $site->siteId,
                    'enabledByDefault' => $site->enabledByDefault,
                ];
            }

            return $supportedSites;
        }

        return [\Craft::$app->getSites()->getPrimarySite()->id];
    }

    /**
     * Returns whether the current user can edit the element.
     */
    public function isEditable(): bool
    {
        return PermissionHelper::canEditEventOverride($this);
    }

    public function can(string $permission): bool
    {
        $currentUser = \Craft::$app->getUser()->getIdentity();
        if ($currentUser->admin) {
            return true;
        }

        if (!isset($this->id)) {
            return false;
        }

        return \Craft::$app->getUserPermissions()->doesUserHavePermission($currentUser->id, $permission);
    }

    public function canView(User $user): bool
    {
        return true;
    }

    /**
     * Returns the element's CP edit URL.
     *
     * @throws InvalidConfigException
     */
    public function getCpEditUrl(): ?string
    {
        if (!$this->isEditable()) {
            return null;
        }

        $siteHandle = $this->getSite()->handle;

        return UrlHelper::cpUrl('calendar/events/'.$this->id.'/'.$siteHandle);
    }

    /**
     * Returns the field layout used by this element.
     */
    public function getFieldLayout(): ?FieldLayout
    {
        if (!$this->calendarId) {
            return null;
        }

        $fieldLayout = $this->event->getCalendar()->getFieldLayout();
        if (!$fieldLayout) {
            $fieldLayout = new FieldLayout();
        }

        if ($this->event->getCalendar()->hasTitleField) {
            $tabs = $fieldLayout->getTabs();

            if (empty($tabs)) {
                $tab = new FieldLayoutTab();
                $tab->name = 'Content';
                $tab->setLayout($fieldLayout);

                $fieldLayout->setTabs([$tab]);

                $tabs = $fieldLayout->getTabs();
            }

            $hasTitle = !empty(
                array_filter(
                    $tabs,
                    function (FieldLayoutTab $tab) {
                        foreach ($tab->getElements() as $element) {
                            if ($element instanceof TitleField) {
                                return true;
                            }
                        }

                        return false;
                    }
                )
            );

            if (!$hasTitle) {
                $firstTab = reset($tabs);
                if ($firstTab) {
                    $titleLabel = $this->event->getCalendar()->titleLabel;

                    $firstTab->setElements(
                        array_merge([
                            new TitleField([
                                'label' => $titleLabel,
                                'title' => $titleLabel,
                                'name' => 'title',
                            ]),
                        ], $firstTab->getElements())
                    );
                }
            }
        }

        return $fieldLayout;
    }

    public function getCalendar(): CalendarModel
    {
        return Calendar::getInstance()->calendars->getCalendarById($this->event->calendarId);
    }

    public function getAuthorId(): ?int
    {
        return $this->authorId;
    }

    public function getAuthor(): ?User
    {
        if ($this->authorId) {
            return \Craft::$app->users->getUserById($this->authorId);
        }

        return null;
    }

    public function getStartTime(): null|Carbon|\DateTime|string
    {
        return $this->startTime;
    }

    public function getStartTimeLocalized(): null|Carbon|\DateTime|string
    {
        return $this->startTimeLocalized;
    }

    public function getEndTime(): null|Carbon|\DateTime|string
    {
        return $this->endTime;
    }

    public function getEndTimeLocalized(): null|Carbon|\DateTime|string
    {
        return $this->endTimeLocalized;
    }

    public function getDateCreated(): null|Carbon|\DateTime|string
    {
        return $this->dateCreated;
    }

    public function getDuration(): EventDuration
    {
        $startDate = $this->getStartTime();
        $endDate = $this->getEndTime();

        if ($this->isAllDay()) {
            $endDate = $endDate->copy()->addSecond();
        }

        return new EventDuration($startDate->diff($endDate));
    }

    public function isAllDay(): bool
    {
        return (bool) $this->allDay;
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }

    public function canDelete(User $user): bool
    {
        return $this->isEditable($this);
    }

    public function canSave(User $user): bool
    {
        return $this->isEditable($this);
    }

    /**
     * @throws Exception if reasons
     */
    public function beforeSave(bool $isNew): bool
    {
        $this->updateTitle();

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        $insertData = [
            'authorId' => $this->authorId,
            'date' => $this->date,
            'startTime' => $this->startTime->toDateTimeString(),
            'endTime' => $this->endDatendTimee->toDateTimeString(),
            'allDay' => (bool) $this->allDay,
        ];

        $db = \Craft::$app->db;
        if ($isNew) {
            $insertData['id'] = $this->id;

            $db->createCommand()
                ->insert(self::TABLE, $insertData)
                ->execute()
            ;
        } else {
            $db->createCommand()
                ->update(self::TABLE, $insertData, ['id' => $this->id])
                ->execute()
            ;
        }

        parent::afterSave($isNew);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['startTime'], 'validateDates'];

        return $rules;
    }

    public function validateDates(): void
    {
        if ($this->startTime >= $this->endTime) {
            $this->addError('startTime', Calendar::t('Start Time must be before End Time'));
        }

        if ($this->startTime->diffInDays($this->endTime, true) > 1) {
            $this->addError('startTime', Calendar::t('The maximum time span of an override event is a day'));
        }
    }

    /**
     * We override actions from Element as we dont want to append View, Edit and Delete actions.
     * We only want our custom Status, Delete and Restore actions.
     *
     * {@inheritdoc}
     */
    public static function actions(string $source): array
    {
        $actions = Collection::make(static::defineActions($source));

        // Give plugins a chance to modify them
        $event = new RegisterElementActionsEvent([
            'source' => $source,
            'actions' => $actions->all(),
        ]);

        BaseEvent::trigger(static::class, self::EVENT_REGISTER_ACTIONS, $event);

        return $event->actions;
    }

    public function attributes(): array
    {
        $names = parent::attributes();
        $names[] = 'authorId';
        $names[] = 'author';

        // Hide Author from Craft Solo
        if (\craft\enums\CmsEdition::Solo === \Craft::$app->getEdition()) {
            unset($names['authorId'], $names['author']);
        }

        return $names;
    }

    public function extraFields(): array
    {
        $names = parent::extraFields();
        $names[] = 'authorId';
        $names[] = 'author';

        // Hide Author from Craft Solo
        if (\craft\enums\CmsEdition::Solo === \Craft::$app->getEdition()) {
            unset($names['authorId'], $names['author']);
        }

        return $names;
    }
}