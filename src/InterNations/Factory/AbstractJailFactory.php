<?php
namespace InterNations\Component\TypeJail\Factory;

use InterNations\Component\TypeJail\Exception\JailException;
use InterNations\Component\TypeJail\Exception\HierarchyException;
use InterNations\Component\TypeJail\Exception\InvalidArgumentException;
use InterNations\Component\TypeJail\Util\TypeUtil;
use ProxyManager\Configuration;
use ProxyManager\Generator\ClassGenerator;
use ProxyManager\ProxyGenerator\AccessInterceptorValueHolderGenerator;
use ProxyManager\ProxyGenerator\ProxyGeneratorInterface;
use ReflectionClass;

abstract class AbstractJailFactory implements JailFactoryInterface
{
    const VERSION = '0.1.0';

    /** @var array */
    private $checkedClasses = [];

    /** @var AccessInterceptorValueHolderGenerator */
    protected $generator;

    /** @var MethodSeparator */
    protected $methodSeparator;

    /** @var Configuration */
    protected $configuration;

    /** @param Configuration $configuration */
    public function __construct(Configuration $configuration = null)
    {
        $this->configuration = $configuration ?: new Configuration();
    }

    public function createInstanceJail($instance, $class)
    {
        if (!is_object($instance)) {
            throw InvalidArgumentException::invalidType($instance, 'object');
        }

        if (!is_string($class)) {
            throw InvalidArgumentException::invalidType($class, 'string');
        }

        $instanceClass = new ReflectionClass($instance);
        $superClass = new ReflectionClass($class);

        if (!TypeUtil::isSuperTypeOf($instanceClass, $superClass)) {
            throw HierarchyException::hierarchyMismatch($instanceClass, $superClass);
        }

        list($prohibitedMethods) = $this->getMethodSeparator()->separateMethods($instanceClass, $superClass);

        $deny = static function ($proxy, $instance, $method, $params, &$returnEarly) use ($class) {
            throw JailException::jailedMethod($method, get_class($instance), $class);
        };

        $proxyClassName = $this->generateProxyForSuperClass($instanceClass, $superClass);
        return new $proxyClassName(
            $instance,
            count($prohibitedMethods) > 0
                ? array_combine($prohibitedMethods, array_fill(0, count($prohibitedMethods), $deny))
                : []
        );
    }

    public function createAggregateJail($instanceAggregate, $class)
    {
        if (!TypeUtil::isTraversable($instanceAggregate)) {
            throw InvalidArgumentException::invalidType($instanceAggregate, ['array', 'Traversable']);
        }

        if (!is_string($class)) {
            throw InvalidArgumentException::invalidType($class, 'string');
        }

        $proxyAggregate = [];

        foreach ($instanceAggregate as $instance) {
            $proxyAggregate[] = $this->createInstanceJail($instance, $class);
        }

        return $proxyAggregate;
    }

    private function generateProxyForSuperClass(ReflectionClass $class, ReflectionClass $superClass)
    {
        $surrogateClassName = $this->getSurrogateClassName($class, $superClass);

        if (isset($this->checkedClasses[$surrogateClassName])) {
            return $this->checkedClasses[$surrogateClassName];
        }

        $proxyParameters = [
            'cacheKey' => $surrogateClassName,
            'factory'  => get_class($this),
            'version'  => static::VERSION,
        ];
        $proxyClassName = $this
            ->configuration
            ->getClassNameInflector()
            ->getProxyClassName($class->getName(), $proxyParameters);

        if (!class_exists($proxyClassName)) {
            $baseClass = $this->getBaseClass($class, $superClass);
            $this->generateProxyClass($proxyClassName, $baseClass, $superClass, $proxyParameters);
        }

        return $this->checkedClasses[$surrogateClassName] = $proxyClassName;
    }

    /**
     * @param ReflectionClass $class
     * @param ReflectionClass $superClass
     * @return ReflectionClass
     */
    abstract protected function getBaseClass(ReflectionClass $class, ReflectionClass $superClass);

    /**
     * @param ReflectionClass $class
     * @param ReflectionClass $superClass
     * @return string
     */
    abstract protected function getSurrogateClassName(ReflectionClass $class, ReflectionClass $superClass);

    /** @return ProxyGeneratorInterface */
    abstract protected function getGenerator();

    private function generateProxyClass(
        $proxyClassName,
        ReflectionClass $baseClass,
        ReflectionClass $superClass,
        array $proxyParameters
    )
    {
        $className = $this->configuration->getClassNameInflector()->getUserClassName($baseClass->getName());
        $phpClass = new ClassGenerator($proxyClassName);

        $this->getGenerator()->generate(new ReflectionClass($className), $phpClass, $superClass);

        $this->configuration->getGeneratorStrategy()->generate($phpClass);
        $this->configuration->getProxyAutoloader()->__invoke($proxyClassName);
    }

    /** @return MethodSeparator */
    protected function getMethodSeparator()
    {
        return $this->methodSeparator ?: $this->methodSeparator = new MethodSeparator();
    }
}
