## Declarations

### Overrides

Type inference is a first class citizen in Scramble. So due to this, Scramble usually needs more type info that used in annotations. Overrides are a simple way to modify declarations. 

For example, here is how `JsonResource` overrides look like:
```php
/**
 * @use-template TResource 
 * @mixin TResource
 * @overrides JsonResource
 */
class JsonResource
{
    /** @var TResource */
    protected $resource;
    
    /** @param TResource $resource */
    public function __construct(mixed $resource) {}
}
```
And this annotation handles everything that needs for proper working of

To define 
