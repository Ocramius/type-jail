<?php
namespace InterNations\Component\TypeJail\Generator;

use InterNations\Component\TypeJail\SuperTypeJailInterface;
use ProxyManager\Generator\Util\ClassGeneratorUtils;
use ProxyManager\Proxy\AccessInterceptorInterface;
use ProxyManager\Proxy\ValueHolderInterface;
use ProxyManager\ProxyGenerator\AccessInterceptor\MethodGenerator\SetMethodPrefixInterceptor;
use ProxyManager\ProxyGenerator\AccessInterceptor\MethodGenerator\SetMethodSuffixInterceptor;
use ProxyManager\ProxyGenerator\AccessInterceptor\PropertyGenerator\MethodPrefixInterceptors;
use ProxyManager\ProxyGenerator\AccessInterceptor\PropertyGenerator\MethodSuffixInterceptors;
use ProxyManager\ProxyGenerator\AccessInterceptorValueHolder\MethodGenerator\InterceptedMethod;
use ProxyManager\ProxyGenerator\Assertion\CanProxyAssertion;
use ProxyManager\ProxyGenerator\AccessInterceptorValueHolder\MethodGenerator\Constructor;
use ProxyManager\ProxyGenerator\LazyLoadingValueHolder\PropertyGenerator\ValueHolderProperty;
use ProxyManager\ProxyGenerator\PropertyGenerator\PublicPropertiesMap;
use ProxyManager\ProxyGenerator\ProxyGeneratorInterface;
use ProxyManager\ProxyGenerator\Util\ProxiedMethodsFilter;
use ProxyManager\ProxyGenerator\ValueHolder\MethodGenerator\GetWrappedValueHolderValue;
use ReflectionClass;
use ReflectionMethod;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Reflection\MethodReflection;

class SuperTypeJailGenerator implements ProxyGeneratorInterface
{
    public function generate(
        ReflectionClass $originalClass,
        ClassGenerator $classGenerator,
        ReflectionClass $superClass = null
    )
    {
        CanProxyAssertion::assertClassCanBeProxied($originalClass);

        $publicProperties = new PublicPropertiesMap($originalClass);
        $interfaces = [
            AccessInterceptorInterface::class,
            ValueHolderInterface::class,
            SuperTypeJailInterface::class,
        ];
        $superClass = $superClass ?: $originalClass;

        if ($superClass->isInterface()) {
            $interfaces[] = $superClass->getName();
        } else {
            $classGenerator->setExtendedClass($superClass->getName());
        }

        $classGenerator->setImplementedInterfaces($interfaces);
        $classGenerator->addPropertyFromGenerator($valueHolder = new ValueHolderProperty());
        $classGenerator->addPropertyFromGenerator($prefixInterceptors = new MethodPrefixInterceptors());
        $classGenerator->addPropertyFromGenerator($suffixInterceptors = new MethodSuffixInterceptors());
        $classGenerator->addPropertyFromGenerator($publicProperties);

        array_map(
            static function (MethodGenerator $generatedMethod) use ($originalClass, $classGenerator) {
                ClassGeneratorUtils::addMethodIfNotFinal($originalClass, $classGenerator, $generatedMethod);
            },
            array_merge(
                array_map(
                    static function (ReflectionMethod $method) use (
                        $prefixInterceptors,
                        $suffixInterceptors,
                        $valueHolder
                    ) {
                        return InterceptedMethod::generateMethod(
                            new MethodReflection($method->getDeclaringClass()->getName(), $method->getName()),
                            $valueHolder,
                            $prefixInterceptors,
                            $suffixInterceptors
                        );
                    },
                    ProxiedMethodsFilter::getProxiedMethods($originalClass)
                ),
                [
                    new Constructor($originalClass, $valueHolder, $prefixInterceptors, $suffixInterceptors),
                    new GetWrappedValueHolderValue($valueHolder),
                    new SetMethodPrefixInterceptor($prefixInterceptors),
                    new SetMethodSuffixInterceptor($suffixInterceptors),
                ]
            )
        );
    }
}
