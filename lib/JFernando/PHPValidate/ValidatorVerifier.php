<?php
/**
 * Created by PhpStorm.
 * User: JFernando
 * Date: 27/09/2016
 * Time: 16:24
 */

namespace JFernando\PHPValidate;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Inflector\Inflector;
use JFernando\PHPValidate\Annotation\Params;
use JFernando\PHPValidate\Annotation\Validate;
use JFernando\PHPValidate\Exception\ValidatorException;
use JFernando\PHPValidate\Utils\Messages;
use JFernando\PHPValidate\Utils\Reflection;
use JFernando\PHPValidate\Utils\ValidatorArgs;

class ValidatorVerifier
{
    protected $byGet;
    protected $messages;

    public function __construct( bool $byGet = true, Messages $messages = null )
    {
        $this->byGet = $byGet;

        if ( $messages ) {
            $this->messages = $messages;
        } else {
            $this->messages = new Messages( [] );
        }

    }

    public function validate( $entity, $args = [] )
    {
        $exceptions = [];

        $reflectedClass = new \ReflectionClass( $entity );
        $reader         = new AnnotationReader();

        foreach ( $reflectedClass->getProperties() as $prop ) {
            $annotations = $reader->getPropertyAnnotations( $prop );

            /** @var Params|null $paramsAnnot */
            $paramsAnnot = $reader->getPropertyAnnotation( $prop, Params::class );
            if ( $paramsAnnot ) {
                $name = $prop->getName();
                if ( $paramsAnnot->value ) {
                    $name = $paramsAnnot->value;
                }

                $args = array_merge( $args, [ $name => $this->getValueFrom( $prop, $reflectedClass, $entity ) ] );
            }

            /** @var Validate $annotation */
            foreach ( $annotations as $annotation ) {
                $annotationClass = new \ReflectionClass( $annotation );

                if ( $this->isValidAnnotation( $annotationClass ) && !$this->isSkipped( $annotation, $entity, $prop, $reflectedClass, $args ) ) {
                    $validationErrors = $this->validateAnnotation( $annotation, $prop, $reflectedClass, $entity, $args );
                    $exceptions       = array_merge( $exceptions, $validationErrors );
                }
            }
        }

        return $exceptions;
    }

    public function isValid( $entity, $args = []){
        $erros = $this->validate($entity, $args);

        return count($erros) > 0;
    }

    public function validateError( $entity, $args = []){
        $erros = $this->validate($entity, $args);

        if(count($erros) > 0){
            throw new ValidatorException($erros);
        }
    }


    private function isSkipped( $annotation, $entity, \ReflectionProperty $prop, $class, $args )
    {
        $value = $this->getValueFrom( $prop, $class, $entity );

        if ( is_null( $value ) && $annotation->skipNull ) {
            return true;
        }

        if ( ( is_string( $value ) && $value === '' ) && $annotation->skipBlank ) {
            return true;
        }

        if ( ( is_array( $value ) && count( $value ) === 0 ) && $annotation->skipEmpty ) {
            return true;
        }

        if ( $annotation->skipIf && $this->isSkipIf( $annotation->skipIf, $entity, $value, $args ) ) {
            return true;
        }

        return false;
    }

    private function validateAnnotation( $annotation, \ReflectionProperty $prop, $reflectedClass, $entity, $args )
    {
        $exceptions = [];
        if ( $annotation != null ) {
            $fieldValue = $this->getValueFrom( $prop, $reflectedClass, $entity );

            if ( $annotation->isClass ) {
                if ( $fieldValue === null ) {
                    return [ $this->getNotValidError( $annotation, $prop, $reflectedClass, $args ) ];
                }

                return $this->validate( $fieldValue, $args );
            }

            /** @var Validator $validator */
            $validator = new $annotation->validator;

            $args = $this->injectParams( $entity, $args, $validator );

            $isValid = $validator->isValid( $fieldValue, $annotation->value );

            $args->add('propValue', $fieldValue);
            $args = $args->getArgs();

            if ( !$isValid ) {
                $exceptions[] = $this->getNotValidError( $annotation, $prop, $reflectedClass, $args );
            }
        }

        return $exceptions;
    }

    private function getNotValidError( $annotation, \ReflectionProperty $prop, \ReflectionClass $class, $args )
    {
        $annotationFields            = $this->getAnnotationFields( $annotation );
        $annotationFields[ 'field' ] = $prop->getName();
        $annotationFields[ 'class' ] = $class->getShortName();

        $annotationFields = array_merge( $annotationFields, $args );

        $annotation->code    = $this->formatString( $annotation->code, $annotationFields );
        $annotation->message = $this->messages->get( $annotation->code, $annotation->message );
        $annotation->message = $this->formatString( $annotation->message, $annotationFields );

        return new $annotation->errors( $annotation->code, $annotation->message, $annotationFields );
    }

    private function formatString( string $value, array $params ) : string
    {
        foreach ( $params as $key => $val ) {
            if ( !is_array( $val ) ) {
                if ( is_object( $val ) ) {
                    if ( get_class( $val ) === \DateTime::class ) {
                        $value = str_replace( "#{{$key}}", $val->format( 'dmY' ), $value );
                        continue;
                    }

                    $classMethods = get_class_methods( $val );

                    foreach ( $classMethods as $method ) {
                        if ( $method == '__toString' ) {
                            $value = str_replace( "#{{$key}}", $val->__toString(), $value );
                            continue;
                        }
                    }
                    continue;
                }
                $value = str_replace( "#{{$key}}", strval( $val ), $value );
            }
        }

        return $value;
    }

    private function getAnnotationFields( $annotation ) : array
    {
        $vars            = [];
        $annotationClass = new \ReflectionClass( $annotation );

        foreach ( $annotationClass->getProperties() as $prop ) {
            $vars[ $prop->getName() ] = $this->getValueFrom( $prop, $annotationClass, $annotation );
        }

        return $vars;
    }


    private function getValueFrom( \ReflectionProperty $property, \ReflectionClass $class, $entity )
    {

        if ( $property->isPublic() ) {
            return $property->getValue( $entity );
        }

        if ( $this->byGet ) {
            $name       = $property->getName();
            $methodName = 'get'.Inflector::classify( $name );

            $method = $class->getMethod( $methodName );

            return $method->invoke( $entity );
        }

        $property->setAccessible( true );

        return $property->getValue( $entity );
    }

    private function isValidAnnotation( \ReflectionClass $annotationClass )
    {
        return ( $annotationClass->getName() == Validate::class ) || ( $annotationClass->isSubclassOf( Validate::class ) );
    }

    private function isSkipIf( $class, $entity, $value, $args = [] )
    {
        $instance = new Reflection( $class );
        $instance = $instance->newInstanceWithoutConstructor();

        $this->injectParams($entity, $args, $instance);

        return $instance->isValid( $value, $args );
    }


    private function injectParams( $entity, $args, $validator ) : ValidatorArgs
    {
        $reflectedAnnot = new \ReflectionClass( $validator );
        $reader = new AnnotationReader();
        $valArgs = new ValidatorArgs();
        $valArgs->addAll($args);
        foreach ( $reflectedAnnot->getProperties() as $propAnnot ) {
            /** @var Params $paramAnnot */
            $paramAnnot = $reader->getPropertyAnnotation( $propAnnot, Params::class );
            if ( $paramAnnot !== null ) {
                $args = array_merge( $args, [ 'object' => $entity, 'validatorArgs' => $valArgs ] );
                $propAnnot->setAccessible( true );

                if ( $paramAnnot->value ) {
                    $propAnnot->setValue( $validator, $args[ $paramAnnot->value ] ?? null );
                    continue;
                }

                $propAnnot->setValue( $validator, $args );
            }
        }
        return $valArgs;
    }
}
