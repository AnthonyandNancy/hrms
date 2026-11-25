<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Shared\Authorization\AnchorType;

/**
 * Code-owned whitelist of data-scope templates. A template is only a named
 * choice of one existing AnchorType; there is no predicate, SQL or custom
 * condition language and the write model cannot add entries.
 */
final class DataScopeTemplateCatalog
{
    /**
     * @return list<array{template: string, anchor_type: string, label: string, description: string}>
     */
    public static function templates(): array
    {
        return [
            [
                'template' => 'SELF',
                'anchor_type' => AnchorType::SELF->value,
                'label' => '仅本人',
                'description' => 'The caller own employment only.',
            ],
            [
                'template' => 'MANAGED',
                'anchor_type' => AnchorType::MANAGED->value,
                'label' => '下属',
                'description' => 'Employment rows managed by the caller.',
            ],
            [
                'template' => 'ORG_UNIT',
                'anchor_type' => AnchorType::ORG_UNIT->value,
                'label' => '本部门',
                'description' => 'A single organization unit anchor.',
            ],
            [
                'template' => 'ORG_SUBTREE',
                'anchor_type' => AnchorType::ORG_SUBTREE->value,
                'label' => '本部门及子部门',
                'description' => 'An organization unit and its declared descendants.',
            ],
            [
                'template' => 'LEGAL_ENTITY',
                'anchor_type' => AnchorType::LEGAL_ENTITY->value,
                'label' => '本公司/法人',
                'description' => 'One legal entity.',
            ],
            [
                'template' => 'CASE',
                'anchor_type' => AnchorType::CASE->value,
                'label' => '指定案例',
                'description' => 'One workflow case membership.',
            ],
            [
                'template' => 'PAYROLL_RUN',
                'anchor_type' => AnchorType::PAYROLL_RUN->value,
                'label' => '指定工资批次',
                'description' => 'One payroll run membership.',
            ],
        ];
    }

    /**
     * @return array{template: string, anchor_type: string, label: string, description: string}|null
     */
    public static function find(string $template): ?array
    {
        foreach (self::templates() as $entry) {
            if ($entry['template'] === $template) {
                return $entry;
            }
        }

        return null;
    }
}
