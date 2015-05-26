
<div class="uk-overflow-container">

    <table class="uk-table uk-table-striped uk-margin-top" if="{ entries.length }">
        <thead>
            <tr>
                <th width="20"></th>
                <th width="20"><input type="checkbox" data-check="all"></th>
                <th each="{field,idx in fields}">
                    { field.label || field.name }
                </th>
                <th width="20"></th>
            </tr>
        </thead>
        <tbody name="sortableroot">
            <tr each="{entry,idx in entries}" data-id="{ entry._id }">
                <td class="js-sortable-handle"><i class="uk-icon-arrows"></i></td>
                <td><input type="checkbox" data-check data-id="{ entry._id }"></td>
                <td class="uk-text-truncate" each="{field,idy in parent.fields}" if="{ field.name != '_modified' }">
                    <a class="uk-link-muted" href="@route('/collections/entry/'.$collection['name'])/{ parent.entry._id }">
                        { String(parent.entry[field.name] === undefined ? '': parent.entry[field.name]) }
                    </a>
                </td>
                <td>{ (new Intl.DateTimeFormat()).format( new Date( 1000 * entry._modified )) }</td>
                <td>
                    <span class="uk-float-right" data-uk-dropdown="\{mode:'click'\}">

                        <a class="uk-icon-bars"></a>

                        <div class="uk-dropdown uk-dropdown-flip">
                            <ul class="uk-nav uk-nav-dropdown">
                                <li class="uk-nav-header">@lang('Actions')</li>
                                <li><a href="@route('/collections/entry/'.$collection['name'])/{ entry._id }">@lang('Edit')</a></li>
                                <li><a onclick="{ parent.remove }">@lang('Delete')</a></li>
                            </ul>
                        </div>
                    </span>
                </td>
            </tr>
        </tbody>
    </table>

</div>