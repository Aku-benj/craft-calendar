<?php

namespace Solspace\Calendar\migrations;

use craft\db\Migration;
use Solspace\Calendar\Elements\EventOverride;
use Solspace\Calendar\Elements\Event;
use Solspace\Calendar\Library\Migrations\ForeignKey;
use Solspace\Calendar\Library\Migrations\Table;

/**
 * m251201_192345_AddEventOverrideTable migration.
 */
class m251201_192345_AddEventOverrideTable extends Migration
{
    public function safeUp(): bool
    {
        $overrideTable = EventOverride::TABLE_STD;
        $eventTable = Event::TABLE_STD;

        $table = (new Table($overrideTable))
            ->addField('id', $this->primaryKey())
            ->addField('eventId', $this->integer()->notNull())
            ->addField('date', $this->dateTime()->notNull())
            ->addField('startTime', $this->dateTime())
            ->addField('endTime', $this->dateTime())
            ->addField('allDay', $this->boolean())
            ->addField('authorId', $this->integer())
            ->addForeignKey('id', 'elements', 'id', ForeignKey::CASCADE)
            ->addForeignKey('eventId', $eventTable, 'id', ForeignKey::CASCADE)
            ->addIndex(['eventId', 'date'], true, 'overrides_')
        ;

        $this->createTable($table->getDatabaseName(), $table->getFieldArray(), $table->getOptions());

        foreach ($table->getIndexes() as $index) {
            $this->createIndex(
                $index->getName(),
                $table->getDatabaseName(),
                $index->getColumns(),
                $index->isUnique()
            );
        }

        foreach ($table->getForeignKeys() as $foreignKey) {
            $this->addForeignKey(
                $foreignKey->getName(),
                $table->getDatabaseName(),
                $foreignKey->getColumn(),
                $foreignKey->getDatabaseReferenceTableName(),
                $foreignKey->getReferenceColumn(),
                $foreignKey->getOnDelete(),
                $foreignKey->getOnUpdate()
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $overrideTable = EventOverride::tableName();

        $this->dropTable($overrideTable);

        return true;
    }
}
