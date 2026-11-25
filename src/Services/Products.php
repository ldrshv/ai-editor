<?php

namespace AiEditor\Services;

use modX;
use Throwable;

/**
 * Товары miniShop2: чтение данных товара (цена, артикул, опции).
 * Запись добавляется отдельным шагом в ChangeService.
 */
class Products
{
    public const PRODUCT_CLASS = 'msProduct';
    public const CATEGORY_CLASS = 'msCategory';

    /** Поля msProductData, которые отдаём наружу */
    public const FIELDS = [
        'article', 'price', 'old_price', 'weight', 'vendor', 'made_in',
        'new', 'popular', 'favorite', 'tags', 'color', 'size', 'image', 'thumb',
    ];

    private const BOOLEANS = ['new', 'popular', 'favorite'];
    private const NUMBERS = ['price', 'old_price', 'weight'];

    /** @var modX */
    private $modx;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    /**
     * Стоит ли на сайте miniShop2 и загружается ли его модель.
     */
    public function installed(): bool
    {
        $path = MODX_CORE_PATH . 'components/minishop2/model/';

        if (!is_dir($path)) {
            return false;
        }

        $this->modx->addPackage('minishop2', $path);

        return (bool) $this->modx->loadClass('msProductData');
    }

    /**
     * Данные товара для get_resource.
     * null — ресурс не товар или miniShop2 не установлен.
     * ['error' => ...] — товар, но данные прочитать не удалось: это не «пустая цена».
     */
    public function read(int $id, string $classKey): ?array
    {
        if ($classKey !== self::PRODUCT_CLASS || !$this->installed()) {
            return null;
        }

        try {
            $data = $this->modx->getObject('msProductData', ['id' => $id]);

            if (!$data) {
                return ['error' => 'У товара ' . $id . ' нет записи msProductData'];
            }

            $fields = [];

            foreach (self::FIELDS as $field) {
                $value = $data->get($field);

                if (in_array($field, self::BOOLEANS, true)) {
                    $value = (bool) $value;
                } elseif (in_array($field, self::NUMBERS, true)) {
                    $value = $value === null ? null : (float) $value;
                } elseif ($field === 'vendor') {
                    $value = (int) $value;
                }

                $fields[$field] = $value;
            }

            $vendor = null;

            if ($fields['vendor'] > 0 && ($object = $this->modx->getObject('msVendor', $fields['vendor']))) {
                $vendor = ['id' => $fields['vendor'], 'name' => (string) $object->get('name')];
            }

            $options = [];

            foreach ($this->modx->getIterator('msProductOption', ['product_id' => $id]) as $option) {
                $options[(string) $option->get('key')][] = $option->get('value');
            }

            return ['fields' => $fields, 'vendor' => $vendor, 'options' => $options];
        } catch (Throwable $exception) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[ai-editor] product ' . $id . ': ' . $exception->getMessage());

            return ['error' => 'Не удалось прочитать данные товара, подробности в журнале MODX'];
        }
    }
}
