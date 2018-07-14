<?php declare(strict_types=1);
namespace Phan\Plugin\Internal;

use Phan\AST\ContextNode;
use Phan\AST\UnionTypeVisitor;
use Phan\Exception\CodeBaseException;
use Phan\Exception\IssueException;
use Phan\Exception\NodeException;
use Phan\Config;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Type;
use Phan\Language\UnionType;
use Phan\PluginV2;
use Phan\PluginV2\PostAnalyzeNodeCapability;
use Phan\PluginV2\PluginAwarePostAnalysisVisitor;
use ast\Node;
use ast;

// ThrowAnalyzerPlugin analyzes throw statements and
// compares them against the phpdoc (at)throws annotations

class ThrowAnalyzerPlugin extends PluginV2 implements PostAnalyzeNodeCapability
{
    /**
     * This is invalidated every time this plugin is loaded (e.g. for tests)
     * @var ?UnionType
     */
    public static $configured_ignore_throws_union_type = null;

    public static function getPostAnalyzeNodeVisitorClassName() : string
    {
        self::$configured_ignore_throws_union_type = null;
        if (Config::getValue('warn_about_undocumented_exceptions_thrown_by_invoked_functions')) {
            return ThrowRecursiveVisitor::class;
        }
        return ThrowVisitor::class;
    }
}

class ThrowVisitor extends PluginAwarePostAnalysisVisitor
{
    /**
     * @var array<int,Node> Dynamic
     * @suppress PhanReadOnlyProtectedProperty set by the framework
     */
    protected $parent_node_list;

    /**
     * @return void
     */
    public function visitThrow(Node $node)
    {
        $context = $this->context;
        if (!$context->isInFunctionLikeScope()) {
            return;
        }
        $code_base = $this->code_base;

        $union_type = UnionTypeVisitor::unionTypeFromNode($code_base, $context, $node->children['expr']);
        $union_type = $this->withoutCaughtUnionTypes($union_type);
        if ($union_type->isEmpty()) {
            // Give up if we don't know
            // TODO: Infer throwable, if the original $union_type was empty
            // and there are no try/catch blocks wrapping this.
            return;
        }
        $analyzed_function = $context->getFunctionLikeInScope($code_base);

        foreach ($this->parent_node_list as $parent) {
            if ($parent->kind !== ast\AST_TRY) {
                continue;
            }
            foreach ($parent->children['catches']->children as $catch_node) {
                $caught_union_type = UnionTypeVisitor::unionTypeFromClassNode($code_base, $context, $catch_node->children['class']);
                foreach ($union_type->getTypeSet() as $type) {
                    if (!$type->asExpandedTypes($code_base)->canCastToUnionType($caught_union_type)) {
                        $union_type = $union_type->withoutType($type);
                        if ($union_type->isEmpty()) {
                            return;
                        }
                    }
                }
            }
        }
        $this->warnAboutPossiblyThrownType($node, $analyzed_function, $union_type);
    }

    protected function withoutCaughtUnionTypes(UnionType $union_type) : UnionType
    {
        if ($union_type->isEmpty()) {
            // Give up if we don't know
            return $union_type;
        }

        foreach ($this->parent_node_list as $parent) {
            if ($parent->kind !== ast\AST_TRY) {
                continue;
            }
            foreach ($parent->children['catches']->children as $catch_node) {
                $caught_union_type = UnionTypeVisitor::unionTypeFromClassNode($this->code_base, $this->context, $catch_node->children['class']);
                foreach ($union_type->getTypeSet() as $type) {
                    if ($type->asExpandedTypes($this->code_base)->canCastToUnionType($caught_union_type)) {
                        $union_type = $union_type->withoutType($type);
                        if ($union_type->isEmpty()) {
                            return $union_type;
                        }
                    }
                }
            }
        }
        return $union_type;
    }

    /**
     * @return void
     */
    protected function warnAboutPossiblyThrownType(
        Node $node,
        FunctionInterface $analyzed_function,
        UnionType $union_type,
        FunctionInterface $call = null
    ) {
        foreach ($union_type->getTypeSet() as $type) {
            $expanded_type = $type->asExpandedTypes($this->code_base);
            if (!$this->shouldWarnAboutThrowType($expanded_type)) {
                continue;
            }
            $throws_union_type = $analyzed_function->getThrowsUnionType();
            if ($throws_union_type->isEmpty()) {
                if ($call !== null) {
                    $this->emitIssue(
                        Issue::ThrowTypeAbsentForCall,
                        $node->lineno,
                        $analyzed_function->getRepresentationForIssue(),
                        (string)$union_type,
                        $call->getRepresentationForIssue()
                    );
                } else {
                    $this->emitIssue(
                        Issue::ThrowTypeAbsent,
                        $node->lineno,
                        $analyzed_function->getRepresentationForIssue(),
                        (string)$union_type
                    );
                }
                continue;
            }
            if (!$expanded_type->canCastToUnionType($throws_union_type)) {
                if ($call !== null) {
                    $this->emitIssue(
                        Issue::ThrowTypeMismatchForCall,
                        $node->lineno,
                        $analyzed_function->getRepresentationForIssue(),
                        (string)$union_type,
                        $call->getRepresentationForIssue(),
                        $throws_union_type
                    );
                } else {
                    $this->emitIssue(
                        Issue::ThrowTypeMismatch,
                        $node->lineno,
                        $analyzed_function->getRepresentationForIssue(),
                        (string)$union_type,
                        $throws_union_type
                    );
                }
            }
        }
    }

    protected static function calculateConfiguredIgnoreThrowsUnionType() : UnionType
    {
        $throws_union_type = new UnionType();
        foreach (Config::getValue('exception_classes_with_optional_throws_phpdoc') as $type_string) {
            if (!\is_string($type_string) || $type_string === '') {
                continue;
            }
            $throws_union_type = $throws_union_type->withUnionType(UnionType::fromStringInContext($type_string, new Context(), Type::FROM_PHPDOC));
        }
        return $throws_union_type;
    }

    protected function getConfiguredIgnoreThrowsUnionType() : UnionType
    {
        return ThrowAnalyzerPlugin::$configured_ignore_throws_union_type ?? (ThrowAnalyzerPlugin::$configured_ignore_throws_union_type = $this->calculateConfiguredIgnoreThrowsUnionType());
    }

    /**
     * Check if the user wants to warn about a given throw type.
     */
    protected function shouldWarnAboutThrowType(UnionType $expanded_type) : bool
    {
        $ignore_union_type = $this->getConfiguredIgnoreThrowsUnionType();
        if ($ignore_union_type->isEmpty()) {
            return true;
        }
        return !$expanded_type->canCastToUnionType($ignore_union_type);
    }
}

class ThrowRecursiveVisitor extends ThrowVisitor
{
    /**
     * @return void
     * @override
     */
    public function visitCall(Node $node)
    {
        $context = $this->context;
        if (!$context->isInFunctionLikeScope()) {
            return;
        }
        $code_base = $this->code_base;
        $analyzed_function = $context->getFunctionLikeInScope($code_base);
        try {
            $function_list_generator = (new ContextNode(
                $code_base,
                $context,
                $node->children['expr']
            ))->getFunctionFromNode();

            foreach ($function_list_generator as $invoked_function) {
                \assert($invoked_function instanceof FunctionInterface);
                // Check the types that can be thrown by this call.
                $this->warnAboutPossiblyThrownType(
                    $node,
                    $analyzed_function,
                    $this->withoutCaughtUnionTypes($invoked_function->getThrowsUnionType())
                );
            }
        } catch (CodeBaseException $e) {
            // ignore it.
        }
    }

    /**
     * @return void
     * @override
     */
    public function visitMethodCall(Node $node)
    {
        $context = $this->context;
        if (!$context->isInFunctionLikeScope()) {
            return;
        }
        $code_base = $this->code_base;
        $method_name = $node->children['method'];

        if (!\is_string($method_name)) {
            $method_name = UnionTypeVisitor::anyStringLiteralForNode($code_base, $context, $method_name);
            if (!\is_string($method_name)) {
                return;
            }
        }
        $analyzed_function = $context->getFunctionLikeInScope($code_base);

        try {
            $invoked_method = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getMethod($method_name, false);
        } catch (IssueException $exception) {
            // do nothing, PostOrderAnalysisVisitor should catch this
            return;
        } catch (NodeException $exception) {
            return;
        }
        // Check the types that can be thrown by this call.
        $this->warnAboutPossiblyThrownType(
            $node,
            $analyzed_function,
            $this->withoutCaughtUnionTypes($invoked_method->getThrowsUnionType()),
            $invoked_method
        );
    }

    /**
     * @return void
     * @override
     */
    public function visitStaticCall(Node $node)
    {
        $context = $this->context;
        if (!$context->isInFunctionLikeScope()) {
            return;
        }
        $code_base = $this->code_base;
        $method_name = $node->children['method'];
        try {
            // Get a reference to the method being called
            $invoked_method = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getMethod($method_name, true, true);  // @phan-suppress-current-line PhanPartialTypeMismatchArgument
        } catch (\Exception $exception) {
            // Ignore IssueException, unexpected exceptions, etc.
            return;
        }

        $analyzed_function = $context->getFunctionLikeInScope($code_base);

        // Check the types that can be thrown by this call.
        $this->warnAboutPossiblyThrownType(
            $node,
            $analyzed_function,
            $this->withoutCaughtUnionTypes($invoked_method->getThrowsUnionType()),
            $invoked_method
        );
    }
}