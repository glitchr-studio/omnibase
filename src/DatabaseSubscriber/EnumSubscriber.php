<?php

namespace Base\DatabaseSubscriber;

use Base\Database\Type\EnumType;
use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Schema\Column;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

class EnumSubscriber implements EventSubscriber
{
    public function getSubscribedEvents(): array
    {
        return [
            ToolEvents::postGenerateSchema,
        ];
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs)
    {
        $columns = [];
        
        foreach ($eventArgs->getSchema()->getTables() as $table) {
            foreach ($table->getColumns() as $column) {
                if ($column->getType() instanceof EnumType) {
                    $columns[] = $column;
                }
            }
        }

        /** @var Column $column */
        foreach ($columns as $column) {

            // The type is found again from "(DC2Type:...)" and its declaration rebuilt from the code: the
            // values must be in the comment too, or a value added (an application's ROLE_PRO) is a column
            // the schema tool reads as unchanged, and no migration ever adds it.
            $type = $column->getType();
            $enum = $type->lookupName($type);
            $column->setComment(trim(sprintf('(DC2Type:%s) %s', $enum, implode(',', $type::getPermittedValues()))));
        }
    }
}