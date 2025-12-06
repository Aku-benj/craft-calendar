<?php

namespace Solspace\Calendar\Services;

use craft\base\Component;
use craft\base\Field;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;
use Solspace\Calendar\Elements\EventOverride;
use yii\base\Exception;

class OverridesService extends Component
{

    /**
     * @throws \Throwable
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function saveOverride(EventOverride $override, bool $validateContent = true): bool
    {
        $override->validate();

        $transaction = \Craft::$app->db->beginTransaction();

        try {
            $isSaved = \Craft::$app->elements->saveElement($override, $validateContent);
            if (!$isSaved) {
                return false;
            }

            $isSaved = $this->_respectNonTranslatableFields($override);
            if (!$isSaved) {
                return false;
            }

            if (null !== $transaction) {
                $transaction->commit();
            }

            return true;
        } catch (\Exception $e) {
            if (null !== $transaction) {
                $transaction->rollBack();
            }

            throw $e;
        }

        return false;
    }

    public function getOverrideForDate(int $eventId, \DateTime $date, ?int $siteId = null): ?EventOverride
    {
        $override = EventOverride::find()
            ->siteId($siteId)
            ->eventId($eventId)
            ->date($date)
            ->one();

        if (empty($override)) {
            return null;
        }

        return $override;
    }

    public function getOverrideById(int $overrideId, ?int $siteId = null): ?EventOverride
    {
        $override = EventOverride::find()
            ->siteId($siteId)
            ->id($overrideId)
            ->one();
        
        if (empty($override)) {
            return null;
        }

        return $override;
    }

    /**
     * @throws \Throwable
     */
    public function deleteOverride(EventOverride $override): bool
    {
        $transaction = \Craft::$app->db->beginTransaction();

        try {
            $isDeleted = \Craft::$app->elements->deleteElementById($override->id, EventOverride::class, null, true); // Hard Delete (without trash)

            if ($isDeleted) {
                if (null !== $transaction) {
                    $transaction->commit();
                }

                return true;
            }
        } catch (\Exception $e) {
            if (null !== $transaction) {
                $transaction->rollBack();
            }

            throw $e;
        }

        return false;
    }

    /**
     * If we have an event with multi-site enabled and a non-translatable fields, we need to respect the non-translatable field values.
     *
     * @throws \Throwable
     * @throws ElementNotFoundException
     * @throws Exception
     */
    private function _respectNonTranslatableFields(EventOverride $event): bool
    {
        if ($event->id && $event::isLocalized() && \Craft::$app->getIsMultiSite()) {
            $otherSiteEvents = [];

            $hasNonTranslatableFields = false;

            // Grab the other sites ids using the supported site ids for $event.
            // So if $event siteId is 1 and $event supports site ids in 1, 2 and 3, we want to grab 2 and 3...
            $supportedSites = ArrayHelper::index(ElementHelper::supportedSitesForElement($event), 'siteId');
            $otherSiteIds = ArrayHelper::withoutValue(array_keys($supportedSites), $event->siteId);

            if (!empty($otherSiteIds)) {
                foreach ($otherSiteIds as $otherSiteId) {
                    $otherSiteEvent = $this->getOverrideById($event->id, $otherSiteId);

                    if ($otherSiteEvent) {
                        $otherSiteEvents[] = $otherSiteEvent;
                    }
                }
            }

            $fieldLayout = $event->getFieldLayout();

            // If no field layout, there is nothing to process
            if (!$fieldLayout) {
                return true;
            }

            $fieldLayoutTabs = $fieldLayout->getTabs();

            // If no field layout tabs (which shouldn't be possible if no fields), there is nothing to process
            if (!$fieldLayoutTabs) {
                return true;
            }

            foreach ($fieldLayoutTabs as $fieldLayoutTab) {
                foreach ($fieldLayoutTab->getElements() as $element) {
                    if ($element instanceof CustomField && Field::TRANSLATION_METHOD_NONE === $element->getField()->translationMethod) {
                        // We've found a field that is non-translatable in $event
                        $hasNonTranslatableFields = true;

                        // Lets grab the field handle and value
                        $fieldHandle = $element->getField()->handle;
                        $fieldValue = $event->getFieldValue($fieldHandle);

                        // Loop over the same event in the other site ids and update the non-translatable field value
                        foreach ($otherSiteEvents as $otherSiteEvent) {
                            $otherSiteEvent->setFieldValue($fieldHandle, $fieldValue);
                        }
                    }
                }
            }

            // Save the same event in the other sites
            if ($hasNonTranslatableFields) {
                foreach ($otherSiteEvents as $otherSiteEvent) {
                    $isSaved = \Craft::$app->elements->saveElement($otherSiteEvent, false, false, false);

                    // If any of the other site events didn't save, we want to bail out and throw an error
                    if (!$isSaved) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
