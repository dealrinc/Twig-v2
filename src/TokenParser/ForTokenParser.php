<?php

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 * (c) Armin Ronacher
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Twig\TokenParser;

use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\ListExpression;
use Twig\Node\Expression\Variable\AssignContextVariable;
use Twig\Node\ForElseNode;
use Twig\Node\ForNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Token;

/**
 * Loops over each item of a sequence.
 *
 *   <ul>
 *    {% for user in users %}
 *      <li>{{ user.username|e }}</li>
 *    {% endfor %}
 *   </ul>
 *
 * @internal
 */
final class ForTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();
        $targets = $this->parseAssignmentExpression();
        $stream->expect(Token::OPERATOR_TYPE, 'in');
        $seq = $this->parser->parseExpression();

        // Restore the legacy "{% for x in seq if cond %}" syntax (removed in Twig 3.0)
        // by desugaring into "{% for x in seq|filter(x => cond) %}".
        if ($stream->nextIf(Token::NAME_TYPE, 'if')) {
            $ifLine = $stream->getCurrent()->getLine();
            $ifExpr = $this->parser->parseExpression();

            // filter() invokes the callback as ($value, $key) — match that order.
            if (\count($targets) > 1) {
                $keyName = $targets->getNode('0')->getAttribute('name');
                $valueName = $targets->getNode('1')->getAttribute('name');
                $arrowParams = new ListExpression([
                    new AssignContextVariable($valueName, $ifLine),
                    new AssignContextVariable($keyName, $ifLine),
                ], $ifLine);
            } else {
                $valueName = $targets->getNode('0')->getAttribute('name');
                $arrowParams = new ListExpression([
                    new AssignContextVariable($valueName, $ifLine),
                ], $ifLine);
            }

            $arrow = new ArrowFunctionExpression($ifExpr, $arrowParams, $ifLine);
            $seq = new FilterExpression(
                $seq,
                $this->parser->getEnvironment()->getFilter('filter'),
                new Nodes([$arrow], $ifLine),
                $ifLine
            );
        }

        $stream->expect(Token::BLOCK_END_TYPE);
        $body = $this->parser->subparse([$this, 'decideForFork']);
        if ('else' == $stream->next()->getValue()) {
            $elseLineno = $stream->getCurrent()->getLine();
            $stream->expect(Token::BLOCK_END_TYPE);
            $else = new ForElseNode($this->parser->subparse([$this, 'decideForEnd'], true), $elseLineno);
        } else {
            $else = null;
        }
        $stream->expect(Token::BLOCK_END_TYPE);

        if (\count($targets) > 1) {
            $keyTarget = $targets->getNode('0');
            $keyTarget = new AssignContextVariable($keyTarget->getAttribute('name'), $keyTarget->getTemplateLine());
            $valueTarget = $targets->getNode('1');
        } else {
            $keyTarget = new AssignContextVariable('_key', $lineno);
            $valueTarget = $targets->getNode('0');
        }
        $valueTarget = new AssignContextVariable($valueTarget->getAttribute('name'), $valueTarget->getTemplateLine());

        return new ForNode($keyTarget, $valueTarget, $seq, null, $body, $else, $lineno);
    }

    public function decideForFork(Token $token): bool
    {
        return $token->test(['else', 'endfor']);
    }

    public function decideForEnd(Token $token): bool
    {
        return $token->test('endfor');
    }

    public function getTag(): string
    {
        return 'for';
    }
}
