<?php

namespace Collective\Html;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\HtmlString;

/**
 * Compatibility alias for the small set of Form methods used by Rokn views.
 *
 * @method static HtmlString open(array $options = [])
 * @method static HtmlString model(mixed $model, array $options = [])
 * @method static HtmlString close()
 * @method static HtmlString text(string $name, mixed $value = null, array $options = [])
 * @method static HtmlString email(string $name, mixed $value = null, array $options = [])
 * @method static HtmlString password(string $name, array $options = [])
 * @method static HtmlString number(string $name, mixed $value = null, array $options = [])
 * @method static HtmlString hidden(string $name, mixed $value = null, array $options = [])
 * @method static HtmlString date(string $name, mixed $value = null, array $options = [])
 * @method static HtmlString textarea(string $name, mixed $value = null, array $options = [])
 * @method static HtmlString select(string $name, iterable $list = [], mixed $selected = null, array $options = [])
 * @method static HtmlString checkbox(string $name, mixed $value = 1, ?bool $checked = null, array $options = [])
 * @method static HtmlString radio(string $name, mixed $value = null, ?bool $checked = null, array $options = [])
 */
final class FormFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'form';
    }
}
