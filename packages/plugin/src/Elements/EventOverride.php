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
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Illuminate\Support\Collection;
use Solspace\Calendar\Calendar;
use Solspace\Calendar\Elements\conditions\EventCondition;
use Solspace\Calendar\Elements\Db\EventOverrideQuery;
use Solspace\Calendar\Library\Duration\EventDuration;
use Solspace\Calendar\Library\Helpers\PermissionHelper;
use Solspace\Calendar\Models\CalendarModel;
use yii\base\Event as BaseEvent;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class EventOverride extends Element
{
    public const TABLE_STD = 'calendar_event_overrides';
    public const TABLE = '{{%' . self::TABLE_STD . '}}';

    public ?int $eventId = null;
    public ?Event $event = null;

    public null|array|int|string $authorId = null;

    public null|Carbon|\DateTime|string $date = null;

    // Overridable fields
    public null|Carbon|\DateTime|string $startTime = null;
    public null|Carbon|\DateTime|string $endTime = null;

    public ?bool $allDay = null;

    public ?string $username = null;
    public ?string $occurence = null; // For routing purposes (base64 encoded -> occurence ID : date)

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

        $this->date = $this->date ? new Carbon($this->date) : null;
        if (empty($this->date)) {
            return false;
        }

        $startTime = $this->startTime ?? $event->startDate;
        if ($startTime instanceof \DateTime) {
            $startTime = $startTime->format('Y-m-d H:i:s');
        }

        $endTime = $this->endTime ?? $event->endDate;
        if ($endTime instanceof \DateTime) {
            $endTime = $endTime->format('Y-m-d H:i:s');
        }

        $this->startTime = new Carbon($startTime);
        $this->endTime = new Carbon($endTime);
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

    public function setStartTime(null|Carbon|\DateTime|string $startTime = null): void
    {
        if ($startTime instanceof \DateTime) {
            // Extract Date from $date and time from $startTime 
            $startTimeFormatted = $this->date->format('Y-m-d ') . $startTime->format('H:i:s');
        } else if (is_string($startTime)) {
            // Extract Date from $date and time from $startTime 
            $startTimeFormatted = DateTimeHelper::toDateTime(['date' => $this->date->format('Y-m-d '), 'time' => $startTime], true);
        }

        if ($startTimeFormatted) {
            $this->startTime = new Carbon($startTimeFormatted);
        }
    }

    public function setEndTime(null|Carbon|\DateTime|string $endTime = null): void
    {
        if ($endTime instanceof \DateTime) {
            // Extract Date from $date and time from $endTime 
            $endTimeFormatted = $this->date->format('Y-m-d ') . $endTime->format('H:i:s');
        } else if (is_string($endTime)) {
            // Extract Date from $date and time from $endTime 
            $endTimeFormatted = DateTimeHelper::toDateTime(['date' => $this->date->format('Y-m-d '), 'time' => $endTime], true);
        }

        if ($endTimeFormatted) {
            $this->endTime = new Carbon($endTimeFormatted);
        }
    }

    /**
     * @return ElementQueryInterface|EventOverrideQuery
     */
    public static function find(): ElementQueryInterface
    {
        return new EventOverrideQuery(self::class);
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

    public static function create(?int $siteId = null, int $eventId, \DateTime $date): self|null
    {
        $event = Calendar::getInstance()->events->getEventById($eventId, $siteId);
        if (!$event) {
            return null;
        }

        $element = new self([
            "eventId" => $eventId,
            "date" => $date,
        ]);

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
        if (!$this->event->calendarId) {
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

    public function getEndTime(): null|Carbon|\DateTime|string
    {
        return $this->endTime;
    }

    public function getDateCreated(): null|Carbon|\DateTime|string
    {
        return $this->dateCreated;
    }

    public function getDuration(): EventDuration
    {
        $startTime = $this->getStartTime();
        $endTime = $this->getEndTime();

        if ($this->isAllDay()) {
            $endTime = $endTime->copy()->addSecond();
        }

        return new EventDuration($startTime->diff($endTime));
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
        // Don't rewrite event_overrides table values if propagating
        if (!$this->propagating) {

            // Override datas only if they are different
            if ($this->startTime->format('H:i') === $this->event->startDate->format('H:i')) {
                $this->startTime = null;
            }
            if ($this->endTime->format('H:i') === $this->event->endDate->format('H:i')) {
                $this->endTime = null;
            }

            // Fields to be inserted
            $insertData = [
                'authorId' => $this->authorId,
                'startTime' => $this->startTime ? $this->startTime->format('Y-m-d H:i:s') : null,
                'endTime' => $this->endTime ? $this->endTime->format('Y-m-d H:i:s') : null,
                'allDay' => $this->allDay ? $this->allDay : null,
            ];

            $db = \Craft::$app->db;
            
            if ($isNew) {
                $insertData['id'] = $this->id;
                $insertData['eventId'] = $this->eventId;
                $insertData['date'] = $this->date->format('Y-m-d');
                
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

        if ($this->startTime->diffInDays($this->endTime, true) >= 1) {
            $this->addError('startTime', Calendar::t('Start and End Time must be on the same day'));
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

    public function setOverrideDatasFromPost(array $post): void
    {
        // Extract Date from $date and time from $startTime
        $startTime = $post['startTime']['time'];
        $endTime = $post['endTime']['time'];
        $allDay = (bool) ($post['allDay'] ?? false);
        $title = $post['title'] ?? $this->event->title;

        if ($allDay !== $this->event->allDay) {
            $this->allDay = $allDay;
        } else {
            $this->allDay = null;
        }

        if ($this->allDay === true) {
            $startTime = "00:00:00";
            $endTime = "23:59:59";
        }

        // Override datas only if they are different
        if ($startTime) {
            $this->setStartTime($startTime);
        }

        if ($endTime) {
            $this->setEndTime($endTime);
        }

        if ($title !== $this->event->title) {
            $this->title = $title;
        } else {
            $this->title = null;
        }
    }
}